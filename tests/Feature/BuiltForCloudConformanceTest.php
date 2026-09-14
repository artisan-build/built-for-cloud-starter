<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Jobs\DeliverOwnershipWebhook;
use ArtisanBuild\BuiltForCloud\Testing\ConsumerConformance;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\Testing\FleetConformance;
use Composer\InstalledVersions;

uses(ContractAssertions::class);

it('passes the version 1 reference-consumer conformance spec', function (): void {
    $packageRoot = InstalledVersions::getInstallPath('artisan-build/built-for-cloud');
    expect($packageRoot)->toBeString();

    $purposeMappings = [];
    foreach ((array) config('built-for-cloud.credentials.app_purposes', []) as $appPurpose => $purpose) {
        $purposeMappings[$appPurpose] = CredentialPurpose::from($purpose);
    }
    ksort($purposeMappings);

    $sourceRoots = [app_path(), base_path('bootstrap'), config_path(), base_path('routes')];
    sort($sourceRoots);
    $providerFiles = [app_path('Providers/AppServiceProvider.php'), $packageRoot.'/src/BuiltForCloudServiceProvider.php'];
    sort($providerFiles);

    $sorted = static function (array $members): array {
        sort($members);

        return $members;
    };

    $expected = [
        'runtime.meta' => [],
        'runtime.auth_schema' => [],
        'runtime.credential_listing' => [],
        'runtime.transport_parity' => [],
        'thin_host' => [],
        'credential_paths' => $sorted([
            'path:Basic|ArtisanBuild\BuiltForCloud\Auth\BasicAuthenticator',
            'path:Bearer|ArtisanBuild\BuiltForCloud\Auth\BearerAuthenticator',
            'path:HMAC|Http\Middleware\VerifyHmacSignature+Hmac\HmacVerifier',
            'path:MCP|Http\Middleware\AuthenticateMcp:store-bearer+v4.public',
            'path:asymmetric|Actions\MintCredential::mintEnrollment',
            'path:enrollment|OnboardingToken+POST:/bfc/claim,/bfc/onboarding/issue,/exchange,/verify',
            'path:system|SubjectType::Operator/Application/Installation+AuditActorType::CliOperator',
        ]),
        'credential_writers' => $sorted([
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintEnrollment',
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSecretBearing',
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSigningKey',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithEnrollment',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithPendingSigningKey',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithSecret',
            'ArtisanBuild\BuiltForCloud\OwnerCredentialMinter::mintFromHash',
            'ArtisanBuild\BuiltForCloud\UnifiedStoreCredentialMinter::mint',
        ]),
        'legacy_removal' => [],
        'system_authority' => $sorted([
            'ArtisanBuild\BuiltForCloud\Commands\ConsoleReKeyCommand',
            'ArtisanBuild\BuiltForCloud\Commands\ConsoleRetireKeyCommand',
            'ArtisanBuild\BuiltForCloud\Commands\CreateAdminCommand',
            'ArtisanBuild\BuiltForCloud\Commands\CredentialActivateCommand',
            'ArtisanBuild\BuiltForCloud\Commands\CredentialListCommand',
            'ArtisanBuild\BuiltForCloud\Commands\CredentialMintCommand',
            'ArtisanBuild\BuiltForCloud\Commands\CredentialRevokeCommand',
            'ArtisanBuild\BuiltForCloud\Commands\CredentialRotateCommand',
            'ArtisanBuild\BuiltForCloud\Commands\HmacRewrapCommand',
            'ArtisanBuild\BuiltForCloud\Commands\InstallOperatorCredentialCommand',
            'ArtisanBuild\BuiltForCloud\Commands\OutboxDrainCommand',
            'ArtisanBuild\BuiltForCloud\Commands\OwnershipMintClaimCommand',
            'ArtisanBuild\BuiltForCloud\Commands\OwnershipRemintOwnerTokenCommand',
            'ArtisanBuild\BuiltForCloud\Commands\SigningRootProvisionCommand',
            'ArtisanBuild\BuiltForCloud\Commands\SubjectOffboardCommand',
            'ArtisanBuild\BuiltForCloud\Commands\WarnExpiringCredentialsCommand',
            DeliverOwnershipWebhook::class,
        ]),
        'no_signing_path' => [],
        'ui_config_reads' => $sorted([
            'ArtisanBuild\BuiltForCloud\AppPurposeRegistry|built-for-cloud.credentials.app_purposes|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\ManageTransitions|built-for-cloud.ui.managed_transitions|1',
            'ArtisanBuild\BuiltForCloud\LandingManifest|built-for-cloud.manifest|1',
            'ArtisanBuild\BuiltForCloud\LandingPageRegistrar|built-for-cloud.ui.landing_page|1',
            'ArtisanBuild\BuiltForCloud\UiCredentialPurposes|built-for-cloud.ui.credential_purposes|1',
        ]),
        'mcp_delegated' => [],
    ];

    $report = (new FleetConformance($this))->assert(new ConsumerConformance(
        consumer: 'built-for-cloud-starter',
        consumerRoot: base_path(),
        packageRoot: $packageRoot,
        sourceRoots: $sourceRoots,
        providerFiles: $providerFiles,
        runtimeAssertions: ['auth_schema', 'credential_listing', 'meta', 'transport_parity'],
        capabilities: ['credentials', 'tokens'],
        purposeMappings: $purposeMappings,
        mcpServer: null,
        expected: $expected,
    ));

    expect($report->passed)->toBeTrue()
        ->and(array_keys($report->families))->toBe(ConsumerConformance::FAMILIES);
});
