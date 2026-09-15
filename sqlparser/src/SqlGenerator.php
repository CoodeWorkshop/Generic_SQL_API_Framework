<?php

require_once __DIR__ . '/SqlParser.php';
require_once __DIR__ . '/SqlCapabilityAnalyzer.php';
require_once __DIR__ . '/SqlToApiMapper.php';
require_once __DIR__ . '/../../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../../app/Requests/QueryRequestNormalizer.php';

class SqlGenerator
{
    public function generate(string $sql): array
    {
        $ast = (new SqlParser())->parse($sql);
        $analysis = (new SqlCapabilityAnalyzer())->analyze($ast);
        $analysis['pipeline'] = [
            'parser' => 'Successfully parsed the SQL into an AST.',
            'capability' => $analysis['unsupported'] === []
                ? 'Parsed constructs are eligible for public-contract mapping.'
                : 'One or more parsed constructs are outside the public contract.',
            'mapping' => 'Not started.',
            'validation' => 'Not started.',
        ];

        if ($analysis['unsupported'] !== []) {
            return [
                'success' => false,
                'message' => 'SQL parsed, but contains a capability limitation.',
                'analysis' => $analysis,
                'error' => [
                    'code' => 'SQL_CAPABILITY_LIMITATION',
                    'stage' => 'capability',
                    'details' => array_map(
                        fn (string $message): array => ['path' => 'sql', 'message' => $message],
                        $analysis['unsupported']
                    ),
                ],
            ];
        }

        try {
            $candidate = (new SqlToApiMapper())->map($ast);
            $analysis['pipeline']['mapping'] = 'Mapped AST to public Universal API JSON.';
        } catch (SqlMappingException $exception) {
            $analysis['pipeline']['mapping'] = $exception->getMessage();
            return [
                'success' => false,
                'message' => 'SQL parsed, but cannot be mapped to the current public API contract.',
                'candidate' => null,
                'analysis' => $analysis,
                'error' => [
                    'code' => 'SQL_MAPPING_ERROR',
                    'stage' => 'mapping',
                    'details' => [['path' => 'sql', 'message' => $exception->getMessage()]],
                ],
            ];
        }

        try {
            (new QueryRequestValidator())->validate($candidate);
            (new QueryRequestNormalizer())->normalize($candidate);
            $analysis['pipeline']['validation'] = 'Accepted by QueryRequestValidator and normalization.';
        } catch (ApiRequestException $exception) {
            $analysis['pipeline']['validation'] = 'Rejected by QueryRequestValidator.';
            return [
                'success' => false,
                'message' => 'Generated JSON was rejected by the existing request validator.',
                // Never expose partial JSON that failed the public safety boundary.
                'candidate' => null,
                'analysis' => $analysis,
                'error' => [
                    'code' => 'GENERATED_REQUEST_INVALID',
                    'stage' => 'validation',
                    'details' => $exception->getDetails(),
                ],
            ];
        }

        return [
            'success' => true,
            'message' => 'API JSON generated and validated.',
            'request' => $candidate,
            'analysis' => $analysis,
        ];
    }
}
