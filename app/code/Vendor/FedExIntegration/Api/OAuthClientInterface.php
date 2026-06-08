<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Api;

interface OAuthClientInterface
{
    public function getAccessToken(null|int|string $storeId = null, bool $forceRefresh = false): string;

    public function invalidateToken(null|int|string $storeId = null): void;
}
