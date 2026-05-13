<?php

declare(strict_types=1);

use App\Application\Actions\Auth\LoginAction;
use App\Application\Actions\Auth\LogoutAction;
use App\Application\Actions\Auth\MeAction;
use App\Application\Actions\Auth\RefreshAction;
use App\Application\Actions\Auth\RegisterAction;
use App\Application\Middleware\JwtAuthMiddleware;
use App\Application\Actions\Template\CreateTemplateAction;
use App\Application\Actions\Template\DeleteTemplateAction;
use App\Application\Actions\Template\GalleryAction;
use App\Application\Actions\Template\GetTemplateAction;
use App\Application\Actions\Template\ListMyTemplatesAction;
use App\Application\Actions\Template\UpdateTemplateAction;
use App\Application\Actions\Project\CreateProjectAction;
use App\Application\Actions\Project\DownloadProjectAction;
use App\Application\Actions\Project\GetProjectAction;
use App\Application\Actions\Project\GetProjectStatusAction;
use App\Application\Actions\Project\ListProjectsAction;
use App\Application\Actions\Project\MapColumnsAction;
use App\Application\Actions\Project\PreviewRowAction;
use App\Application\Actions\Project\SubmitProjectAction;
use App\Application\Actions\Project\UploadCsvAction;
use App\Application\Actions\Project\UploadImagesAction;
use App\Application\Actions\Asset\ListAssetsAction;
use App\Application\Actions\Payment\CreateOrderAction;
use App\Application\Actions\Payment\ListPricingAction;
use App\Application\Actions\Payment\VerifyPaymentAction;
use App\Application\Actions\Payment\WebhookAction;
use App\Application\Actions\Payment\UseCreditsAction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy as Group;

return function (App $app) {
    $app->options('/{routes:.*}', function (Request $request, Response $response) {
        return $response;
    });

    $app->get('/ping', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode(['status' => 'ok']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $app->group('/api/v1', function (Group $group) {

        // ── Public auth routes ────────────────────────────────────────────────
        $group->post('/auth/register', RegisterAction::class);
        $group->post('/auth/login',    LoginAction::class);
        $group->post('/auth/refresh',  RefreshAction::class);

        // ── Public gallery & pricing ──────────────────────────────────────────
        $group->get('/templates/gallery', GalleryAction::class);
        $group->get('/pricing',           ListPricingAction::class);
        $group->post('/payments/webhook', WebhookAction::class);

        // ── Protected: auth ───────────────────────────────────────────────────
        $group->group('', function (Group $protected) {
            $protected->post('/auth/logout', LogoutAction::class);
            $protected->get('/auth/me',      MeAction::class);
            $protected->get('/me/assets',    ListAssetsAction::class);
        })->add(JwtAuthMiddleware::class);

        // ── Protected: templates ──────────────────────────────────────────────
        $group->group('/templates', function (Group $g) {
            $g->get('',         ListMyTemplatesAction::class);
            $g->post('',        CreateTemplateAction::class);
            $g->get('/{id}',    GetTemplateAction::class);
            $g->put('/{id}',    UpdateTemplateAction::class);
            $g->delete('/{id}', DeleteTemplateAction::class);
        })->add(JwtAuthMiddleware::class);

        // ── Protected: projects ───────────────────────────────────────────────
        $group->group('/projects', function (Group $g) {
            $g->get('',                  ListProjectsAction::class);
            $g->post('',                 CreateProjectAction::class);
            $g->get('/{id}',             GetProjectAction::class);
            $g->get('/{id}/status',      GetProjectStatusAction::class);  // ← poll during generation
            $g->get('/{id}/download',    DownloadProjectAction::class);   // ← get ZIP URL when done
            $g->post('/{id}/csv',        UploadCsvAction::class);
            $g->post('/{id}/map',        MapColumnsAction::class);
            $g->post('/{id}/images',     UploadImagesAction::class);
            $g->get('/{id}/preview',     PreviewRowAction::class);
            $g->post('/{id}/submit',     SubmitProjectAction::class);
        })->add(JwtAuthMiddleware::class);

        // ── Protected: payments ───────────────────────────────────────────────
        $group->group('/payments', function (Group $g) {
            $g->post('/create-order', CreateOrderAction::class);
            $g->post('/verify',       VerifyPaymentAction::class);
            $g->post('/use-credits', UseCreditsAction::class);
        })->add(JwtAuthMiddleware::class);
    });
};