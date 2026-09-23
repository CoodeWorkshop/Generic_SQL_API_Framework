<?php

require_once __DIR__ . '/DatabaseConfigurationResolver.php';
require_once __DIR__ . '/DatabaseCredentialResolver.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';

class DatabaseConfigurationMigrator
{
    public function migrate(string $configPath): void
    {
        $storedConfiguration = DatabaseConfigurationResolver::readStored($configPath);
        if (DatabaseConfigurationResolver::usesEncryption($storedConfiguration)) {
            throw new DatabaseCredentialException('Database configuration is already encrypted.');
        }

        // Resolve the former password-only format before sealing the complete
        // configuration. Plaintext configurations pass through unchanged.
        if (array_key_exists('password', $storedConfiguration)) {
            $storedConfiguration['password'] = DatabaseCredentialResolver::resolve(
                $storedConfiguration['password']
            );
        }

        $encryptedConfiguration = (new DatabaseCredentialEncryption())
            ->encryptConfiguration($storedConfiguration);

        try {
            JsonFileStore::save($configPath, $encryptedConfiguration);
            if (DatabaseConfigurationResolver::load($configPath) !== $storedConfiguration) {
                throw new DatabaseCredentialException('Unable to verify encrypted database configuration.');
            }
        } catch (DatabaseCredentialException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DatabaseCredentialException('Unable to replace database configuration.');
        }
    }
}
