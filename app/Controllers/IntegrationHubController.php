<?php

declare(strict_types=1);

final class IntegrationHubController
{
    public function mostrar(): void
    {
        View::render('admin/integrations', []);
    }
}
