<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\View;
use Aura\Service\CarePlanService;
use InvalidArgumentException;

/**
 * Prescriptions d'un séjour (Epic 4) : liste, écran B3 « Nouvelles prescriptions » (saisie d'une
 * ordonnance) et validation d'une ordonnance par un médecin (RG5, AURA-23).
 *
 * Même fonctionnement que l'admission (PatientController) : validation serveur avant toute
 * écriture, réponse JSON pour form.js (AJAX) ; après un enregistrement, retour sur l'écran B3
 * avec un message (POST → redirect → GET).
 */
final class CarePlanController
{
    /** Clé d'erreur hors champ (ordonnance refusée) : affichée en tête du formulaire. */
    public const FORM_ERROR = 'form';

    private const LIST_VIEW = 'careplan/list';
    private const FORM_VIEW = 'careplan/form';
    /** form.js envoie les formulaires (AJAX) ; care-plan.js anime les listes et le panier de B3. */
    private const FORM_SCRIPTS = ['/js/form.js', '/js/care-plan.js'];

    public function __construct(private CarePlanService $carePlanService)
    {
    }

    /** GET /care-plans?idEpisodeOfCare=… : prescriptions du séjour, bandeau en tête (RG2). */
    public function list(): void
    {
        $idEpisodeOfCare = $this->requestedEpisodeOfCare();
        if ($idEpisodeOfCare === null) {
            return;
        }

        $page = $this->carePlanService->listing($idEpisodeOfCare);
        if ($page === null) {
            View::erreur(404, sprintf('Aucun séjour ne porte l\'identifiant « %d ».', $idEpisodeOfCare));
            return;
        }

        View::page(self::LIST_VIEW, $page, $this->title('Prescriptions', $page));
    }

    /**
     * GET /care-plans/new?idEpisodeOfCare=… : écran B3. Après un enregistrement ou une validation,
     * l'URL porte aussi &saved=… ou &validated=… (numéro de l'ordonnance, pour le message).
     */
    public function newForm(): void
    {
        $idEpisodeOfCare = $this->requestedEpisodeOfCare();
        if ($idEpisodeOfCare === null) {
            return;
        }

        $form = $this->carePlanService->form(
            $idEpisodeOfCare,
            self::positiveInt($_GET['saved'] ?? null),
            self::positiveInt($_GET['validated'] ?? null)
        );
        if ($form === null) {
            View::erreur(404, sprintf('Aucun séjour ne porte l\'identifiant « %d ».', $idEpisodeOfCare));
            return;
        }
        if (!$form['isOpen']) {
            View::erreur(400, 'Ce séjour est clos : on ne peut plus y ajouter de prescription.');
            return;
        }

        $this->showForm($form, '');
    }

    /** POST /care-plans/create?idEpisodeOfCare=… : enregistre l'ordonnance et toutes ses lignes. */
    public function create(): void
    {
        $idEpisodeOfCare = $this->requestedEpisodeOfCare();
        if ($idEpisodeOfCare === null) {
            return;
        }

        $input = self::readInput($_POST);
        $errors = $this->carePlanService->checkInput($input);
        if ($errors !== []) {
            $this->respondWithErrors($idEpisodeOfCare, $errors);
            return;
        }

        try {
            $idCarePlan = $this->carePlanService->prescribe($idEpisodeOfCare, $input);
        } catch (InvalidArgumentException $e) {
            // Refus métier (séjour clos, droits RG5…) : rien n'a été écrit.
            $this->respondWithErrors($idEpisodeOfCare, [self::FORM_ERROR => $e->getMessage()]);
            return;
        }

        $this->redirect(sprintf('/care-plans/new?idEpisodeOfCare=%d&saved=%d', $idEpisodeOfCare, $idCarePlan));
    }

    /** POST /care-plans/validate?idEpisodeOfCare=… : un médecin valide une ordonnance (RG5, AURA-23). */
    public function validate(): void
    {
        $idEpisodeOfCare = $this->requestedEpisodeOfCare();
        if ($idEpisodeOfCare === null) {
            return;
        }

        $idCarePlan = self::positiveInt($_POST['idCarePlan'] ?? null);
        if ($idCarePlan === null) {
            $this->respondWithErrors($idEpisodeOfCare, [self::FORM_ERROR => 'Ordonnance manquante ou invalide.']);
            return;
        }

        try {
            $this->carePlanService->validateCarePlan(
                $idEpisodeOfCare,
                $idCarePlan,
                self::text($_POST['idValidator'] ?? null)
            );
        } catch (InvalidArgumentException $e) {
            $this->respondWithErrors($idEpisodeOfCare, [self::FORM_ERROR => $e->getMessage()]);
            return;
        }

        $this->redirect(sprintf(
            '/care-plans/new?idEpisodeOfCare=%d&validated=%d',
            $idEpisodeOfCare,
            $idCarePlan
        ));
    }

    /**
     * POST /care-plans/stop?idEpisodeOfCare=… : un médecin arrête une ligne depuis le dossier du
     * séjour (AURA-22), puis retour au dossier. Champs : idLine, isMedication (1 ou 0), idStoppedBy.
     */
    public function stop(): void
    {
        $idEpisodeOfCare = $this->requestedEpisodeOfCare();
        if ($idEpisodeOfCare === null) {
            return;
        }

        $idLine = self::positiveInt($_POST['idLine'] ?? null);
        $kind = self::text($_POST['isMedication'] ?? null);
        try {
            if ($idLine === null || !in_array($kind, ['0', '1'], true)) {
                throw new InvalidArgumentException('Prescription manquante ou invalide.');
            }
            $this->carePlanService->stopLine(
                $idEpisodeOfCare,
                $kind === '1',
                $idLine,
                self::text($_POST['idStoppedBy'] ?? null)
            );
        } catch (InvalidArgumentException $e) {
            // Erreur affichée dans le formulaire du dossier par form.js ; sans JavaScript, page d'erreur.
            if ($this->expectsJson()) {
                $this->respondJson(422, ['ok' => false, 'erreurs' => [self::FORM_ERROR => $e->getMessage()]]);
            } else {
                View::erreur(422, $e->getMessage());
            }
            return;
        }

        $this->redirect('/sejour?id=' . $idEpisodeOfCare);
    }

    /**
     * Séjour demandé dans l'URL (?idEpisodeOfCare=…). Affiche l'erreur 400 et renvoie null
     * s'il manque ou n'est pas un entier positif.
     */
    private function requestedEpisodeOfCare(): ?int
    {
        $id = self::positiveInt($_GET['idEpisodeOfCare'] ?? null);
        if ($id === null) {
            View::erreur(400, 'Identifiant de séjour manquant ou invalide.');
        }

        return $id;
    }

    /** @param array<string, mixed> $form données de CarePlanService::form() */
    private function showForm(array $form, string $formError): void
    {
        View::page(
            self::FORM_VIEW,
            $form + ['formError' => $formError],
            $this->title('Nouvelles prescriptions', $form),
            self::FORM_SCRIPTS
        );
    }

    /**
     * AJAX : erreurs en JSON pour form.js. Sinon (JavaScript désactivé) : écran B3 réaffiché
     * avec les messages en tête.
     *
     * @param array<string, string> $errors
     */
    private function respondWithErrors(int $idEpisodeOfCare, array $errors): void
    {
        if ($this->expectsJson()) {
            // Clé « erreurs » : c'est celle que lit public/js/form.js (partagé avec l'admission).
            $this->respondJson(422, ['ok' => false, 'erreurs' => $errors]);
            return;
        }

        $form = $this->carePlanService->form($idEpisodeOfCare);
        if ($form === null) {
            View::erreur(404, sprintf('Aucun séjour ne porte l\'identifiant « %d ».', $idEpisodeOfCare));
            return;
        }
        http_response_code(422);
        $this->showForm($form, implode(' ', $errors));
    }

    /** Après un enregistrement : form.js suit « redirect » ; sans JavaScript, redirection 303. */
    private function redirect(string $destination): void
    {
        if ($this->expectsJson()) {
            $this->respondJson(200, ['ok' => true, 'redirect' => $destination]);
            return;
        }
        header('Location: ' . $destination, true, 303);
    }

    /** @param array<string, mixed> $page données contenant le bandeau */
    private function title(string $subject, array $page): string
    {
        return sprintf(
            'AURA - %s - %s %s, séjour %s',
            $subject,
            $page['banner']['nom'],
            $page['banner']['prenom'],
            $page['banner']['numeroSejour']
        );
    }

    /**
     * Ordonnance envoyée par l'écran B3 : mode, prescripteur et lignes (lines[n][…], dans l'ordre
     * du panier). Seuls les champs attendus sont gardés ; une valeur non textuelle compte comme vide.
     *
     * @param array<string, mixed> $post
     * @return array{mode: string, idPrescriber: string, lines: list<array<string, string>>}
     */
    private static function readInput(array $post): array
    {
        $lines = [];
        $postedLines = $post['lines'] ?? [];
        foreach (is_array($postedLines) ? $postedLines : [] as $postedLine) {
            $line = [];
            foreach (CarePlanService::LINE_FIELDS as $field) {
                $line[$field] = self::text(is_array($postedLine) ? ($postedLine[$field] ?? null) : null);
            }
            $lines[] = $line;
        }

        return [
            'mode' => self::text($post['mode'] ?? null),
            'idPrescriber' => self::text($post['idPrescriber'] ?? null),
            'lines' => $lines,
        ];
    }

    /** Entier positif écrit en chiffres (« 12 »), sinon null. */
    private static function positiveInt(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1 ? (int) $value : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /** Requête AJAX (form.js) : en-tête Accept: application/json ou X-Requested-With. */
    private function expectsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

        return str_contains($accept, 'application/json')
            || strcasecmp($requestedWith, 'XMLHttpRequest') === 0;
    }

    /** @param array<string, mixed> $body */
    private function respondJson(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
    }
}
