<?php
declare(strict_types=1);

namespace App\Application\Actions\Template;

use App\Application\Actions\Action;
use App\Domain\Template\TemplateRepository;
use App\Domain\User\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

class DeleteTemplateAction extends Action
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
        $user = $this->request->getAttribute('user');
        $id   = (int) $this->resolveArg('id');

        $template = $this->templates->findById($id);
        if (!$template) {
            throw new HttpNotFoundException($this->request, 'Template not found');
        }

        if ($template->getUserId() !== $user->getId()) {
            throw new HttpForbiddenException($this->request, 'Access denied');
        }

        $this->templates->delete($id);

        return $this->respondWithData(['message' => 'Template deleted']);
    }
}