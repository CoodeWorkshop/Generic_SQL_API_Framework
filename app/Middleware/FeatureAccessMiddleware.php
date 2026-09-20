<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

final class FeatureAccessMiddleware extends Middleware
{
    private AdminConfigurationRepository $configuration;

    public function __construct(?AdminConfigurationRepository $configuration = null)
    {
        $this->configuration = $configuration ?? new AdminConfigurationRepository();
    }

    public function handle(array $request): void
    {
        $action = $request['action'] ?? null;
        if (!is_string($action)) return;
        $features = $this->configuration->load()['features'];
        if (in_array($action, ['insert', 'update', 'delete', 'upsert'], true)) {
            $this->requireFeature($features, 'writeData', 'Write Data');
            return;
        }
        if (str_starts_with($action, 'metadata.')) {
            $this->requireFeature($features, 'metadata', 'Metadata');
            return;
        }
        if (in_array($action, ['select', 'sql', 'union', 'unionAll', 'procedure', 'function', 'tableFunction'], true)) {
            $this->requireFeature($features, 'readData', 'Read Data');
            if (!$features['pagination'] && $this->containsProperty($request, 'pagination')) {
                $this->disabled('Pagination');
            }
            if (!$features['sorting'] && $this->containsProperty($request, 'sort')) {
                $this->disabled('Sorting');
            }
        }
    }

    private function requireFeature(array $features, string $key, string $label): void
    {
        if (($features[$key] ?? false) !== true) $this->disabled($label);
    }

    private function containsProperty(array $value, string $property): bool
    {
        if (array_key_exists($property, $value)) return true;
        foreach ($value as $child) {
            if (is_array($child) && $this->containsProperty($child, $property)) return true;
        }
        return false;
    }

    private function disabled(string $feature): never
    {
        throw new ApiRequestException(
            $feature . ' is disabled by the administrator.',
            'FEATURE_DISABLED',
            [['path' => 'action', 'message' => $feature . ' is disabled.']],
            403
        );
    }
}
