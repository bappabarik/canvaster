<?php
declare(strict_types=1);

namespace App\Application\Actions\Project;

use App\Application\Actions\Action;
use App\Application\Validation\RequestValidator;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\TemplateRepository;
use App\Domain\User\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Respect\Validation\Validator as v;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

class CreateProjectAction extends Action
{
    public function __construct(
        LoggerInterface     $logger,
        private ProjectRepository  $projects,
        private TemplateRepository $templates,
        private RequestValidator   $validator,
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        /** @var User $user */
        $user = $this->request->getAttribute('user');

        $data = $this->validator->validate(
            (array) $this->request->getParsedBody(),
            [
                'name'          => v::notEmpty()->stringType()->length(1, 150),
                'template_id'   => v::notEmpty()->intType()->positive(),
                'output_format' => v::optional(v::in(['pdf','png','zip_pdf','zip_png'])),
            ]
        );

        $template = $this->templates->findById((int) $data['template_id']);
        if (!$template) {
            throw new HttpNotFoundException($this->request, 'Template not found');
        }

        // Template must be public OR owned by the user
        if (!$template->isPublic() && $template->getUserId() !== $user->getId()) {
            throw new HttpBadRequestException($this->request, 'Template not accessible');
        }

        // Snapshot the canvas at project creation time — critical
        $project = $this->projects->create([
            'user_id'               => $user->getId(),
            'template_id'           => $template->getId(),
            'canvas_snapshot_json'  => $template->getCanvasJson(),
            'placeholders_snapshot' => $template->getPlaceholders(),
            'name'                  => $data['name'],
            'output_format'         => $data['output_format'] ?? 'zip_png',
        ]);

        return $this->respondWithData($project->jsonSerialize(), 201);
    }
}