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
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        Database::setConfigPath(null);

        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->temporaryFiles = [];
    }

    public function testWithoutReadableConfigurationFile(): void
    {
        Database::setConfigPath(sys_get_temp_dir() . '/aura_config_inexistante_' . uniqid() . '.php');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/^Configuration manquante/');

        Database::get();
    }

    public function testUnreachableHostWithoutCredentialLeak(): void
    {
        $password = 'mot-de-passe-tres-secret-' . uniqid();
        Database::setConfigPath($this->createTemporaryConfig('127.0.0.1', 1, 'aura_test', 'aura', $password));

        try {
            Database::get();
            $this->fail('Une ConfigurationException était attendue : le port 1 ne doit pas répondre.');
        } catch (ConfigurationException $e) {
            $message = $e->getMessage();
            $this->assertStringStartsWith('Connexion à la base impossible : ', $message);
            $this->assertStringNotContainsString($password, $message);
            $this->assertStringNotContainsString('mysql:host=', $message);
            // getTraceAsString() tronque les arguments : on inspecte les arguments bruts de chaque frame.
            foreach ($e->getTrace() as $frame) {
                $this->assertStringNotContainsString($password, var_export($frame['args'] ?? [], true));
            }
            // L'exception PDO d'origine n'est pas chaînée : sa trace contiendrait le mot de passe.
            $this->assertNull($e->getPrevious());
        }
    }

    public function testRealConnectionAndSingleInstance(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');

        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test de connexion réelle ignoré (hors CI).');
        }

        Database::setConfigPath($this->createTemporaryConfig(
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

    private function createTemporaryConfig(
        string $host,
        int $port,
        string $name,
        string $user,
        string $password
    ): string {
        $file = tempnam(sys_get_temp_dir(), 'aura_config_');
        $this->assertNotFalse($file, 'Impossible de créer un fichier temporaire.');
        $this->temporaryFiles[] = $file;

        $content = '<?php return ' . var_export(['db' => [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'password' => $password,
        ]], true) . ';';
        file_put_contents($file, $content);

        return $file;
    }
}
