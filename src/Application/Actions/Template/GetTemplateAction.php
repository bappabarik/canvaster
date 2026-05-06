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

class GetTemplateAction extends Action
{
    public function __construct(
        LoggerInterface    $logger,
        private TemplateRepository $templates,
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $id       = (int) $this->resolveArg('id');
        $template = $this->templates->findById($id);

        if (!$template) {
            throw new HttpNotFoundException($this->request, 'Template not found');
        }

        /** @var User $user */
        $user = $this->request->getAttribute('user');

        // Must be public OR owned by the requesting user
        if (!$template->isPublic() && $template->getUserId() !== $user->getId()) {
            throw new HttpForbiddenException($this->request, 'Access denied');
        }

        return $this->respondWithData($template->jsonSerialize());
    }
}