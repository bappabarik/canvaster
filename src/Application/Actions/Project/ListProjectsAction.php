<?php
declare(strict_types=1);

namespace App\Application\Actions\Project;

use App\Application\Actions\Action;
use App\Domain\Project\ProjectRepository;
use App\Domain\User\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ListProjectsAction extends Action
{
    public function __construct(
        LoggerInterface   $logger,
        private ProjectRepository $projects,
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        /** @var User $user */
        $user     = $this->request->getAttribute('user');
        $projects = $this->projects->findByUser($user->getId());

        return $this->respondWithData([
            'projects' => array_map(fn($p) => $p->jsonSerialize(), $projects),
            'total'    => count($projects),
        ]);
    }
}