<?php
declare(strict_types=1);

namespace App\Application\Actions\Template;

use App\Application\Actions\Action;
use App\Application\Services\PlaceholderExtractor;
use App\Application\Validation\RequestValidator;
use App\Domain\Template\TemplateRepository;
use App\Domain\User\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Respect\Validation\Validator as v;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

class UpdateTemplateAction extends Action
{
    public function __construct(
        LoggerInterface      $logger,
        private TemplateRepository   $templates,
        private PlaceholderExtractor $extractor,
        private RequestValidator     $validator,
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

        $data = $this->validator->validate(
            (array) $this->request->getParsedBody(),
            [
                'name'          => v::optional(v::stringType()->length(1, 150)),
                'category'      => v::optional(v::in([
                    'id_card','certificate','invite','badge',
                    'business_card','ticket','label','other',
                ])),
                'canvas_json'   => v::optional(v::stringType()),
                'width_px'      => v::optional(v::intType()->positive()),
                'height_px'     => v::optional(v::intType()->positive()),
                'thumbnail_url' => v::optional(v::url()),
                'is_public'     => v::optional(v::boolType()),
            ]
        );

        // Re-extract placeholders if canvas changed
        if (isset($data['canvas_json'])) {
            $data['placeholders'] = $this->extractor->extract($data['canvas_json']);
        }

        $updated = $this->templates->update($id, $data);

        return $this->respondWithData($updated->jsonSerialize());
    }
}