<?php
declare(strict_types=1);

namespace App\Application\Actions\Template;

use App\Application\Actions\Action;
use App\Application\Services\PlaceholderExtractor;
use App\Application\Services\CloudinaryService; // 1. Added this import
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
        private CloudinaryService    $cloudinaryService // 2. Injected the service here
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
                'name'             => v::optional(v::stringType()->length(1, 150)),
                'category'         => v::optional(v::in([
                    'id_card','certificate','invite','badge',
                    'business_card','ticket','label','other',
                ])),
                'canvas_json'      => v::optional(v::stringType()),
                'width_px'         => v::optional(v::intType()->positive()),
                'height_px'        => v::optional(v::intType()->positive()),
                'is_public'        => v::optional(v::boolType()),
                'thumbnail_base64' => v::optional(v::stringType()), // 3. Accept base64 string
            ]
        );

        // Re-extract placeholders if canvas changed
        if (isset($data['canvas_json'])) {
            $data['placeholders'] = $this->extractor->extract($data['canvas_json']);
        }

        // 4. Handle Cloudinary Upload if a new thumbnail was sent
        if (!empty($data['thumbnail_base64'])) {
            $uploadResult = $this->cloudinaryService->upload(
                $data['thumbnail_base64'], // Base64 string from React
                'bdp/templates/thumbnails',
                uniqid('thumb_')
            );
            
            // Set the generated secure URL in the data array
            $data['thumbnail_url'] = $uploadResult['secure_url'];
            
            // Remove the base64 string from data so it doesn't try to save to the DB
            unset($data['thumbnail_base64']);
        }

        $updated = $this->templates->update($id, $data);

        return $this->respondWithData($updated->jsonSerialize());
    }
}