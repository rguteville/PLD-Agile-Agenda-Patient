<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\CareTeamDao;
use Aura\Dao\Transaction;
use Aura\Domain\CareTeam;
use DateTimeImmutable;
use LogicException;
use PDOException;

/**
 * Équipes et vacations (AURA-30) : au début de chaque vacation, le cadre compose les équipes et leur
 * attribue les patients du service. Deux vacations par jour (backlog : « 2 rotations par jour ») :
 * JOUR de 8 h à 20 h et NUIT de 20 h à 8 h le lendemain, bornes = HYPOTHÈSE à confirmer avec le service.
 * La vacation de nuit porte la date de son début : à 3 h le 15, on est dans la nuit du 14.
 * À ne pas confondre avec les rotations de l'agenda (AgendaService::SHIFTS : 6 h, 14 h, 22 h), autre
 * hypothèse, qui bornent la validation anticipée d'un soin.
 */
final class CareTeamService
{
    /** Vacations : code (CareTeam.shift) => libellé, heures affichées et heure de début. */
    public const SHIFTS = [
        CareTeam::SHIFT_DAY => ['label' => 'Jour', 'hours' => '8 h à 20 h', 'from' => 8],
        CareTeam::SHIFT_NIGHT => ['label' => 'Nuit', 'hours' => '20 h à 8 h', 'from' => 20],
    ];
    private const SHIFT_HOURS = 12;

    /** Longueur maximale du nom d'une équipe (colonne CareTeam.name VARCHAR(30)). */
    public const NAME_MAX = 30;

    public const STATUS_SAVED = 'saved';
    public const STATUS_INVALID = 'invalid';

    public function __construct(
        private ?CareTeamDao $careTeamDao = null,
        private ?Transaction $transaction = null,
    ) {
    }

    /**
     * Vacation en cours à cet instant.
     *
     * @return array{date: DateTimeImmutable, shift: string}
     */
    public static function currentShift(DateTimeImmutable $now): array
    {
        $hour = (int) $now->format('G');
        $today = $now->setTime(0, 0);
        if ($hour < self::SHIFTS[CareTeam::SHIFT_DAY]['from']) {
            return ['date' => $today->modify('-1 day'), 'shift' => CareTeam::SHIFT_NIGHT];
        }
        if ($hour < self::SHIFTS[CareTeam::SHIFT_NIGHT]['from']) {
            return ['date' => $today, 'shift' => CareTeam::SHIFT_DAY];
        }

        return ['date' => $today, 'shift' => CareTeam::SHIFT_NIGHT];
    }

    /**
     * Début (inclus) et fin (exclue) d'une vacation.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public static function window(DateTimeImmutable $date, string $shift): array
    {
        $start = $date->setTime(self::SHIFTS[$shift]['from'], 0);

        return [$start, $start->modify('+' . self::SHIFT_HOURS . ' hours')];
    }

    /** Vacation lue dans l'URL (JOUR / NUIT, insensible à la casse) ; null si absente ou inconnue. */
    public static function parseShift(mixed $value): ?string
    {
        $shift = is_string($value) ? strtoupper(trim($value)) : '';

        return isset(self::SHIFTS[$shift]) ? $shift : null;
    }

    /**
     * Contrôle d'une équipe, PUR : nom (unique parmi les équipes de la vacation), au moins un soignant
     * de la liste, des patients du service, chacun dans une seule équipe de la vacation.
     *
     * @param array<string, mixed> $input name, members (liste d'identifiants), patients (liste de séjours)
     * @param array<string, string> $practitioners soignants proposés : id => libellé
     * @param array<int, string> $episodes séjours en cours du service : id => libellé
     * @param list<CareTeam> $teams équipes déjà composées pour la vacation
     * @return array<string, string> erreurs indexées par champ
     */
    public static function validate(array $input, array $practitioners, array $episodes, array $teams): array
    {
        $errors = [];
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if ($name === '') {
            $errors['name'] = 'Donnez un nom à l\'équipe.';
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = sprintf('Le nom ne doit pas dépasser %d caractères.', self::NAME_MAX);
        } else {
            foreach ($teams as $team) {
                if (mb_strtolower($team->getName()) === mb_strtolower($name)) {
                    $errors['name'] = 'Une équipe porte déjà ce nom pour cette vacation.';
                }
            }
        }

        $members = self::ids($input['members'] ?? []);
        if ($members === []) {
            $errors['members'] = 'Choisissez au moins un soignant.';
        } elseif (array_diff($members, array_map('strval', array_keys($practitioners))) !== []) {
            $errors['members'] = 'Un soignant choisi n\'existe pas.';
        }

        $patients = self::ids($input['patients'] ?? []);
        if (array_diff($patients, array_map('strval', array_keys($episodes))) !== []) {
            $errors['patients'] = 'Un patient choisi n\'est pas hospitalisé dans ce service.';
        } else {
            $taken = [];
            foreach ($teams as $team) {
                foreach ($team->getEpisodeIds() as $idEpisode) {
                    $taken[(string) $idEpisode] = $team->getName();
                }
            }
            foreach ($patients as $idEpisode) {
                if (isset($taken[$idEpisode])) {
                    $errors['patients'] = sprintf(
                        '%s est déjà dans l\'%s : un patient n\'a qu\'une équipe par vacation.',
                        $episodes[(int) $idEpisode],
                        mb_strtolower($taken[$idEpisode])
                    );
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Compose une équipe après contrôle, relu et écrit dans une même transaction.
     *
     * @param array<string, mixed> $input voir validate()
     * @param array<string, string> $practitioners
     * @param array<int, string> $episodes
     * @return array{status: string, errors?: array<string, string>, id?: int}
     * @throws LogicException si le service a été construit sans CareTeamDao ou Transaction
     */
    public function create(
        string $idHealthcareService,
        DateTimeImmutable $date,
        string $shift,
        array $input,
        array $practitioners,
        array $episodes
    ): array {
        if ($this->careTeamDao === null || $this->transaction === null) {
            throw new LogicException('CareTeamService::create() requiert CareTeamDao et Transaction.');
        }

        return $this->transaction->run(function () use (
            $idHealthcareService,
            $date,
            $shift,
            $input,
            $practitioners,
            $episodes
        ): array {
            // Verrou du service puis relecture : deux cadres qui composent en même temps passent l'un après
            // l'autre et le second voit l'équipe du premier (un patient, une équipe par vacation).
            $this->careTeamDao->lockService($idHealthcareService);
            $teams = $this->careTeamDao->findForShift($idHealthcareService, $date, $shift);
            $errors = self::validate($input, $practitioners, $episodes, $teams);
            if ($errors !== []) {
                return ['status' => self::STATUS_INVALID, 'errors' => $errors];
            }
            $members = [];
            foreach (self::ids($input['members']) as $idPractitioner) {
                $members[$idPractitioner] = $practitioners[$idPractitioner];
            }
            try {
                $id = $this->careTeamDao->insert(new CareTeam(
                    null,
                    $idHealthcareService,
                    $date,
                    $shift,
                    trim((string) $input['name']),
                    $members,
                    array_map('intval', self::ids($input['patients'] ?? []))
                ));
            } catch (PDOException $e) {
                // Nom déjà pris (uq_careteam_name) : erreur sur le champ, pas un plantage.
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }

                return ['status' => self::STATUS_INVALID, 'errors' => ['name' => 'Une équipe porte déjà ce nom.']];
            }

            return ['status' => self::STATUS_SAVED, 'id' => $id];
        });
    }

    /**
     * Retire une équipe (suppression logique) ; false si elle n'existe pas ou est déjà retirée.
     *
     * @throws LogicException si le service a été construit sans CareTeamDao
     */
    public function remove(int $idCareTeam, DateTimeImmutable $now): bool
    {
        if ($this->careTeamDao === null) {
            throw new LogicException('CareTeamService::remove() requiert CareTeamDao.');
        }

        return $this->careTeamDao->remove($idCareTeam, $now);
    }

    /**
     * Identifiants cochés, nettoyés et sans doublon.
     *
     * @return list<string>
     */
    private static function ids(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $ids = [];
        foreach ($values as $value) {
            if ((is_string($value) || is_int($value)) && preg_match('/^[A-Za-z0-9_-]{1,20}$/', (string) $value) === 1) {
                $ids[(string) $value] = true;
            }
        }

        return array_map('strval', array_keys($ids));
    }
}
