<?php

declare(strict_types=1);

namespace Aura\Tests\Core;

use Aura\Core\ConfigurationException;
use Aura\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la connexion PDO unique (AURA-11.1).
 *
 * Les fichiers de configuration utilisés sont créés dans le dossier temporaire
 * du système : config/config.php n'est jamais lu ni modifié.
 */
final class DatabaseTest extends TestCase
{
    /** @var string[] fichiers temporaires à supprimer */
    private array $fichiersTemporaires = [];

    protected function tearDown(): void
    {
        Database::setConfigPath(null);

        foreach ($this->fichiersTemporaires as $fichier) {
            if (is_file($fichier)) {
                unlink($fichier);
            }
        }
        $this->fichiersTemporaires = [];
    }

    public function testSansFichierDeConfigurationLisible(): void
    {
        Database::setConfigPath(sys_get_temp_dir() . '/aura_config_inexistante_' . uniqid() . '.php');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/^Configuration manquante/');

        Database::get();
    }

    public function testHoteInjoignableSansFuiteDIdentifiants(): void
    {
        $motDePasse = 'mot-de-passe-tres-secret-' . uniqid();
        Database::setConfigPath($this->creerConfigTemporaire('127.0.0.1', 1, 'aura_test', 'aura', $motDePasse));

        try {
            Database::get();
            $this->fail('Une ConfigurationException était attendue : le port 1 ne doit pas répondre.');
        } catch (ConfigurationException $e) {
            $message = $e->getMessage();
            $this->assertStringStartsWith('Connexion à la base impossible : ', $message);
            $this->assertStringNotContainsString($motDePasse, $message);
            $this->assertStringNotContainsString('mysql:host=', $message);
            // getTraceAsString() tronque les arguments : on inspecte les arguments bruts de chaque frame.
            foreach ($e->getTrace() as $frame) {
                $this->assertStringNotContainsString($motDePasse, var_export($frame['args'] ?? [], true));
            }
            // L'exception PDO d'origine n'est pas chaînée : sa trace contiendrait le mot de passe.
            $this->assertNull($e->getPrevious());
        }
    }

    public function testConnexionReelleEtInstanceUnique(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');

        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test de connexion réelle ignoré (hors CI).');
        }

        Database::setConfigPath($this->creerConfigTemporaire(
            $host,
            (int) (getenv('DB_PORT') ?: 3306),
            $name,
            (string) (getenv('DB_USER') ?: 'aura'),
            (string) (getenv('DB_PASSWORD') ?: '')
        ));

        $pdo = Database::get();

        $this->assertInstanceOf(PDO::class, $pdo);
        $this->assertSame($pdo, Database::get(), 'Deux appels doivent renvoyer la même instance.');
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        $this->assertSame(PDO::FETCH_ASSOC, $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        $this->assertFalse($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
    }

    private function creerConfigTemporaire(
        string $host,
        int $port,
        string $name,
        string $user,
        string $password
    ): string {
        $fichier = tempnam(sys_get_temp_dir(), 'aura_config_');
        $this->assertNotFalse($fichier, 'Impossible de créer un fichier temporaire.');
        $this->fichiersTemporaires[] = $fichier;

        $contenu = '<?php return ' . var_export(['db' => [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'password' => $password,
        ]], true) . ';';
        file_put_contents($fichier, $contenu);

        return $fichier;
    }
}
