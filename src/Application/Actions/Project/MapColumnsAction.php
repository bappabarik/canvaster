<?php
declare(strict_types=1);

namespace App\Application\Actions\Project;

use App\Application\Actions\Action;
use App\Application\Validation\RequestValidator;
use App\Domain\Project\ProjectRepository;
use App\Domain\User\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Respect\Validation\Validator as v;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

class MapColumnsAction extends Action
{
    public function __construct(
        LoggerInterface   $logger,
        private ProjectRepository $projects,
        private RequestValidator  $validator,
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        /** @var User $user */
        $user      = $this->request->getAttribute('user');
        $projectId = (int) $this->resolveArg('id');

        if (!$this->projects->belongsToUser($projectId, $user->getId())) {
            throw new HttpNotFoundException($this->request, 'Project not found');
        }

        $project = $this->projects->findById($projectId);

        if ($project->getTotalRows() === 0) {
            throw new HttpBadRequestException($this->request, 'Upload a CSV first');
        }

        $data = $this->validator->validate(
            (array) $this->request->getParsedBody(),
            [
                // column_map is a JSON object: {"placeholder": "CSV Header"}
                'column_map' => v::notEmpty()->arrayType(),
            ]
        );

        // Validate that all placeholders in the snapshot are mapped
        $placeholders = $project->getPlaceholdersSnapshot();
        $columnMap    = $data['column_map'];
        $missing      = array_diff($placeholders, array_keys($columnMap));

        if (!empty($missing)) {
            throw new HttpBadRequestException(
                $this->request,
                'Missing mappings for placeholders: ' . implode(', ', $missing)
            );
        }

        $updated = $this->projects->update($projectId, ['column_map' => $columnMap]);

        return $this->respondWithData([
            'column_map'  => $updated->getColumnMap(),
            'total_rows'  => $updated->getTotalRows(),
            'placeholders'=> $placeholders,
        ]);
    }
}