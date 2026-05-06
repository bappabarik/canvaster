<?php
declare(strict_types=1);

namespace App\Application\Actions\Template;

use App\Application\Actions\Action;
use App\Domain\Template\TemplateRepository;
use App\Domain\User\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ListMyTemplatesAction extends Action
{
    public function __construct(
        LoggerInterface    $logger,
        private TemplateRepository $templates,
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        /** @var User $user */
        $user      = $this->request->getAttribute('user');
        $templates = $this->templates->findByUser($user->getId());

        return $this->respondWithData([
            'templates' => array_map(fn($t) => $t->jsonSerialize(), $templates),
            'total'     => count($templates),
        ]);
    }
}