<?php declare(strict_types=1);

namespace DOMJudgeBundle\Controller\Jury;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use DOMJudgeBundle\Entity\Contest;
use DOMJudgeBundle\Entity\Judgehost;
use DOMJudgeBundle\Entity\Judging;
use DOMJudgeBundle\Entity\Language;
use DOMJudgeBundle\Entity\Problem;
use DOMJudgeBundle\Entity\Submission;
use DOMJudgeBundle\Entity\SubmissionFileWithSourceCode;
use DOMJudgeBundle\Entity\Team;
use DOMJudgeBundle\Entity\Testcase;
use DOMJudgeBundle\Service\BalloonService;
use DOMJudgeBundle\Service\DOMJudgeService;
use DOMJudgeBundle\Service\EventLogService;
use DOMJudgeBundle\Service\ScoreboardService;
use DOMJudgeBundle\Service\SubmissionService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/jury")
 * @Security("has_role('ROLE_JURY')")
 */
class SubmissionController extends Controller
{
    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var DOMJudgeService
     */
    protected $DOMJudgeService;

    /**
     * @var SubmissionService
     */
    protected $submissionService;

    /**
     * SubmissionController constructor.
     * @param EntityManagerInterface $entityManager
     * @param DOMJudgeService        $DOMJudgeService
     * @param SubmissionService      $submissionService
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        DOMJudgeService $DOMJudgeService,
        SubmissionService $submissionService
    ) {
        $this->entityManager     = $entityManager;
        $this->DOMJudgeService   = $DOMJudgeService;
        $this->submissionService = $submissionService;
    }

    /**
     * @Route("/submissions/", name="jury_submissions")
     */
    public function indexAction(Request $request)
    {
        $viewTypes = [0 => 'newest', 1 => 'unverified', 2 => 'unjudged', 3 => 'all'];
        $view      = 0;
        if (($submissionViewCookie = $this->DOMJudgeService->getCookie('domjudge_submissionview')) &&
            isset($viewTypes[$submissionViewCookie])) {
            $view = $submissionViewCookie;
        }

        if ($request->query->has('view')) {
            $index = array_search($request->query->get('view'), $viewTypes);
            if ($index !== false) {
                $view = $index;
            }
        }

        $response = $this->DOMJudgeService->setCookie('domjudge_submissionview', (string)$view);

        $refresh = [
            'after' => 15,
            'url' => $this->generateUrl('jury_submissions', ['view' => $viewTypes[$view]]),
            'method' => 'updateSubmissionList',
        ];

        $restrictions = [];
        if ($viewTypes[$view] == 'unverified') {
            $restrictions['verified'] = 0;
        }
        if ($viewTypes[$view] == 'unjudged') {
            $restrictions['judged'] = 0;
        }

        $contests = $this->DOMJudgeService->getCurrentContests();
        if ($contest = $this->DOMJudgeService->getCurrentContest()) {
            $contests = [$contest->getCid() => $contest];
        }

        $limit = $viewTypes[$view] == 'newest' ? 50 : 0;

        /** @var Submission[] $submissions */
        list($submissions, $submissionCounts) = $this->submissionService->getSubmissionList($contests, $restrictions,
                                                                                            $limit);

        // Load preselected filters
        $filters          = $this->DOMJudgeService->jsonDecode((string)$this->DOMJudgeService->getCookie('domjudge_submissionsfilter') ?: '[]');
        $filteredProblems = $filteredLanguages = $filteredTeams = [];
        if (isset($filters['problem-id'])) {
            /** @var Problem[] $filteredProblems */
            $filteredProblems = $this->entityManager->createQueryBuilder()
                ->from('DOMJudgeBundle:Problem', 'p')
                ->select('p')
                ->where('p.probid IN (:problemIds)')
                ->setParameter(':problemIds', $filters['problem-id'])
                ->getQuery()
                ->getResult();
        }
        if (isset($filters['language-id'])) {
            /** @var Language[] $filteredLanguages */
            $filteredLanguages = $this->entityManager->createQueryBuilder()
                ->from('DOMJudgeBundle:Language', 'lang')
                ->select('lang')
                ->where('lang.langid IN (:langIds)')
                ->setParameter(':langIds', $filters['language-id'])
                ->getQuery()
                ->getResult();
        }
        if (isset($filters['team-id'])) {
            /** @var Team[] $filteredTeams */
            $filteredTeams = $this->entityManager->createQueryBuilder()
                ->from('DOMJudgeBundle:Team', 't')
                ->select('t')
                ->where('t.teamid IN (:teamIds)')
                ->setParameter(':teamIds', $filters['team-id'])
                ->getQuery()
                ->getResult();
        }

        $data = [
            'refresh' => $refresh,
            'viewTypes' => $viewTypes,
            'view' => $view,
            'submissions' => $submissions,
            'submissionCounts' => $submissionCounts,
            'showContest' => count($contests) > 1,
            'hasFilters' => !empty($filters),
            'filteredProblems' => $filteredProblems,
            'filteredLanguages' => $filteredLanguages,
            'filteredTeams' => $filteredTeams,
        ];

        // For ajax requests, only return the submission list partial
        if ($request->isXmlHttpRequest()) {
            $data['showTestcases'] = true;
            return $this->render('@DOMJudge/jury/partials/submission_list.html.twig', $data);
        }

        return $this->render('@DOMJudge/jury/submissions.html.twig', $data, $response);
    }

    /**
     * @Route("/submissions/{submitId}", name="jury_submission")
     * @throws \Doctrine\ORM\NonUniqueResultException
     * @throws \Exception
     */
    public function viewAction(Request $request, int $submitId)
    {
        $judgingId   = $request->query->get('jid');
        $rejudgingId = $request->query->get('rejudgingid');

        if (isset($judgingId) && isset($rejudgingId)) {
            throw new BadRequestHttpException("You cannot specify jid and rejudgingid at the same time.");
        }

        // If judging ID is not set but rejudging ID is, try to deduce the judging ID from the database.
        if (!isset($judgingId) && isset($rejudgingId)) {
            $judging = $this->entityManager->getRepository(Judging::class)
                ->findOneBy([
                                'submitid' => $submitId,
                                'rejudgingid' => $rejudgingId
                            ]);
            if ($judging) {
                $judgingId = $judging->getJudgingid();
            }
        }

        /** @var Submission|null $submission */
        $submission = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:Submission', 's')
            ->join('s.team', 't')
            ->join('s.problem', 'p')
            ->join('s.language', 'l')
            ->join('s.contest', 'c')
            ->join('s.contest_problem', 'cp')
            ->select('s', 't', 'p', 'l', 'c', 'cp')
            ->andWhere('s.submitid = :submitid')
            ->setParameter(':submitid', $submitId)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$submission) {
            throw new NotFoundHttpException(sprintf('No submission found with ID %d', $submitId));
        }

        $judgingData = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:Judging', 'j', 'j.judgingid')
            ->leftJoin('j.runs', 'jr')
            ->leftJoin('j.rejudging', 'r')
            ->select('j', 'r', 'MAX(jr.runtime) AS max_runtime')
            ->andWhere('j.contest = :contest')
            ->andWhere('j.submission = :submission')
            ->setParameter(':contest', $submission->getContest())
            ->setParameter(':submission', $submission)
            ->groupBy('j.judgingid')
            ->orderBy('j.starttime')
            ->addOrderBy('j.judgingid')
            ->getQuery()
            ->getResult();

        /** @var Judging[] $judgings */
        $judgings    = array_map(function ($data) {
            return $data[0];
        }, $judgingData);
        $maxRunTimes = array_map(function ($data) {
            return $data['max_runtime'];
        }, $judgingData);

        $selectedJudging = null;
        // Find the selected judging
        if ($judgingId !== null) {
            $selectedJudging = $judgings[$judgingId] ?? null;
        } else {
            foreach ($judgings as $judging) {
                if ($judging->getValid()) {
                    $selectedJudging = $judging;
                }
            }
        }

        $claimWarning = null;

        if ($request->get('claim') || $request->get('unclaim')) {
            $user   = $this->DOMJudgeService->getUser();
            $action = $request->get('claim') ? 'claim' : 'unclaim';

            if ($selectedJudging === null) {
                $claimWarning = sprintf('Cannot %s this submission: no valid judging found.', $action);
            } elseif ($selectedJudging->getVerified()) {
                $claimWarning = sprintf('Cannot %s this submission: judging already verified.', $action);
            } elseif (!$user && $action === 'claim') {
                $claimWarning = 'Cannot claim this submission: no jury member specified.';
            } else {
                if (!empty($selectedJudging->getJuryMember()) && $action === 'claim' &&
                    $user->getUsername() !== $selectedJudging->getJuryMember() &&
                    !$request->request->has('forceclaim')) {
                    $claimWarning = sprintf('Submission has been claimed by %s. Claim again on this page to force an update.',
                                            $selectedJudging->getJuryMember());
                } else {
                    $selectedJudging->setJuryMember($action === 'claim' ? $user->getUsername() : null);
                    $this->entityManager->flush();
                    $this->DOMJudgeService->auditlog('judging', $selectedJudging->getJudgingid(), $action . 'ed');

                    if ($action === 'claim') {
                        return $this->redirectToRoute('jury_submission', ['submitId' => $submission->getSubmitid()]);
                    } else {
                        return $this->redirectToRoute('jury_submissions');
                    }
                }
            }
        }

        $unjudgableReasons = [];
        if ($selectedJudging === null) {
            // Determine if this submission is unjudgable

            // First, check if there is an active judgehost that can judge this submission.
            /** @var Judgehost[] $judgehosts */
            $judgehosts  = $this->entityManager->createQueryBuilder()
                ->from('DOMJudgeBundle:Judgehost', 'j')
                ->leftJoin('j.restriction', 'r')
                ->select('j', 'r')
                ->andWhere('j.active = 1')
                ->getQuery()
                ->getResult();
            $canBeJudged = false;
            foreach ($judgehosts as $judgehost) {
                if (!$judgehost->getRestriction()) {
                    $canBeJudged = true;
                    break;
                }

                $queryBuilder = $this->entityManager->createQueryBuilder()
                    ->from('DOMJudgeBundle:Submission', 's')
                    ->select('s')
                    ->join('s.language', 'lang')
                    ->join('s.contest_problem', 'cp')
                    ->andWhere('s.submitid = :submitid')
                    ->andWhere('s.judgehost IS NULL')
                    ->andWhere('lang.allow_judge = 1')
                    ->andWhere('cp.allow_judge = 1')
                    ->andWhere('s.valid = 1')
                    ->setParameter(':submitid', $submission->getSubmitid())
                    ->setMaxResults(1);

                $restrictions = $judgehost->getRestriction()->getRestrictions();
                if (isset($restrictions['contest'])) {
                    $queryBuilder
                        ->andWhere('s.cid IN (:contests)')
                        ->setParameter(':contests', $restrictions['contest']);
                }
                if (isset($restrictions['problem'])) {
                    $queryBuilder
                        ->leftJoin('s.problem', 'p')
                        ->andWhere('p.probid IN (:problems)')
                        ->setParameter(':problems', $restrictions['problem']);
                }
                if (isset($restrictions['language'])) {
                    $queryBuilder
                        ->andWhere('s.langid IN (:languages)')
                        ->setParameter(':languages', $restrictions['language']);
                }

                if ($queryBuilder->getQuery()->getOneOrNullResult()) {
                    $canBeJudged = true;
                }
            }

            if (!$canBeJudged) {
                $unjudgableReasons[] = 'No active judgehost can judge this submission. Edit judgehost restrictions!';
            }

            if (!$submission->getLanguage()->getAllowJudge()) {
                $unjudgableReasons[] = 'Submission language is currently not allowed to be judged!';
            }

            if (!$submission->getContestProblem()->getAllowJudge()) {
                $unjudgableReasons[] = 'Problem is currently not allowed to be judged!';
            }
        }

        $outputDisplayLimit    = (int)$this->DOMJudgeService->dbconfig_get('output_display_limit', 2000);
        $outputTruncateMessage = sprintf("\n[output display truncated after %d B]\n", $outputDisplayLimit);

        $runs       = [];
        $runsOutput = [];
        if ($selectedJudging) {
            $queryBuilder = $this->entityManager->createQueryBuilder()
                ->from('DOMJudgeBundle:Testcase', 't')
                ->join('t.testcase_content', 'tc')
                ->leftJoin('t.judging_runs', 'jr', Join::WITH, 'jr.judging = :judging')
                ->leftJoin('jr.judging_run_output', 'jro')
                ->select('t', 'jr', 'tc.image_thumb AS image_thumb')
                ->andWhere('t.problem = :problem')
                ->setParameter(':judging', $selectedJudging)
                ->setParameter(':problem', $submission->getProblem())
                ->orderBy('t.rank');
            if ($outputDisplayLimit < 0) {
                $queryBuilder
                    ->addSelect('tc.output AS output_reference')
                    ->addSelect('jro.output_run AS output_run')
                    ->addSelect('jro.output_diff AS output_diff')
                    ->addSelect('jro.output_error AS output_error')
                    ->addSelect('jro.output_system AS output_system');
            } else {
                $queryBuilder
                    ->addSelect('TRUNCATE(tc.output, :outputDisplayLimit, :outputTruncateMessage) AS output_reference')
                    ->addSelect('TRUNCATE(jro.output_run, :outputDisplayLimit, :outputTruncateMessage) AS output_run')
                    ->addSelect('TRUNCATE(jro.output_diff, :outputDisplayLimit, :outputTruncateMessage) AS output_diff')
                    ->addSelect('TRUNCATE(jro.output_error, :outputDisplayLimit, :outputTruncateMessage) AS output_error')
                    ->addSelect('TRUNCATE(jro.output_system, :outputDisplayLimit, :outputTruncateMessage) AS output_system')
                    ->setParameter(':outputDisplayLimit', $outputDisplayLimit)
                    ->setParameter(':outputTruncateMessage', $outputTruncateMessage);
            }

            $runResults = $queryBuilder
                ->getQuery()
                ->getResult();

            foreach ($runResults as $runResult) {
                $runs[] = $runResult[0];
                unset($runResult[0]);
                $runResult['terminated'] = preg_match('/timelimit exceeded.*hard (wall|cpu) time/',
                                                      (string)$runResult['output_system']);
                $runsOutput[]            = $runResult;
            }
        }

        if ($submission->getOrigsubmitid()) {
            $lastSubmission = $this->entityManager->getRepository(Submission::class)->find($submission->getOrigsubmitid());
        } else {
            /** @var Submission|null $lastSubmission */
            $lastSubmission = $this->entityManager->createQueryBuilder()
                ->from('DOMJudgeBundle:Submission', 's')
                ->select('s')
                ->andWhere('s.team = :team')
                ->andWhere('s.problem = :problem')
                ->andWhere('s.submittime < :submittime')
                ->setParameter(':team', $submission->getTeam())
                ->setParameter(':problem', $submission->getProblem())
                ->setParameter(':submittime', $submission->getSubmittime())
                ->orderBy('s.submittime', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        /** @var Judging|null $lastJudging */
        $lastJudging = null;
        /** @var Testcase[] $lastRuns */
        $lastRuns = [];
        if ($lastSubmission !== null) {
            $lastJudging = $this->entityManager->createQueryBuilder()
                ->from('DOMJudgeBundle:Judging', 'j')
                ->select('j')
                ->andWhere('j.submission = :submission')
                ->andWhere('j.valid = 1')
                ->setParameter(':submission', $lastSubmission)
                ->orderBy('j.judgingid', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($lastJudging !== null) {
                // Clear the testcases, otherwise Doctrine will use the previous data
                $this->entityManager->clear(Testcase::class);
                $lastRuns = $this->entityManager->createQueryBuilder()
                    ->from('DOMJudgeBundle:Testcase', 't')
                    ->leftJoin('t.judging_runs', 'jr', Join::WITH, 'jr.judging = :judging')
                    ->select('t', 'jr')
                    ->andWhere('t.problem = :problem')
                    ->setParameter(':judging', $lastJudging)
                    ->setParameter(':problem', $submission->getProblem())
                    ->orderBy('t.rank')
                    ->getQuery()
                    ->getResult();
            }
        }

        $twigData = [
            'submission' => $submission,
            'lastSubmission' => $lastSubmission,
            'judgings' => $judgings,
            'maxRunTimes' => $maxRunTimes,
            'selectedJudging' => $selectedJudging,
            'lastJudging' => $lastJudging,
            'runs' => $runs,
            'runsOutput' => $runsOutput,
            'lastRuns' => $lastRuns,
            'unjudgableReasons' => $unjudgableReasons,
            'verificationRequired' => (bool)$this->DOMJudgeService->dbconfig_get('verification_required', false),
            'claimWarning' => $claimWarning,
        ];

        if ($selectedJudging === null) {
            // Automatically refresh page while we wait for judging data.
            $twigData['refresh'] = [
                'after' => 15,
                'url' => $this->generateUrl('jury_submission', ['submitId' => $submission->getSubmitid()]),
            ];
        }

        return $this->render('@DOMJudge/jury/submission.html.twig', $twigData);
    }

    /**
     * @Route("/submissions/by-judging-id/{jid}", name="jury_submission_by_judging")
     */
    public function viewForJudgingAction(Judging $jid)
    {
        return $this->redirectToRoute('jury_submission', [
            'submitId' => $jid->getSubmitid(),
            'jid' => $jid->getJudgingid(),
        ]);
    }

    /**
     * @Route("/submissions/by-external-id/{extid}", name="jury_submission_by_external_id")
     */
    public function viewForExternalIdAction(string $externalId)
    {
        if (!$this->DOMJudgeService->getCurrentContest()) {
            throw new BadRequestHttpException("Cannot determine submission from external ID without selecting a contest.");
        }

        $submission = $this->entityManager->getRepository(Submission::class)
            ->findOneBy([
                            'cid' => $this->DOMJudgeService->getCurrentContest()->getCid(),
                            'externalid' => $externalId
                        ]);

        if (!$submission) {
            throw new NotFoundHttpException(sprintf('No submission found with external ID %s', $externalId));
        }

        return $this->redirectToRoute('jury_submission', [
            'submitId' => $submission->getSubmitid(),
        ]);
    }

    /**
     * @Route("/submissions/{submission}/source", name="jury_submission_source")
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function sourceAction(Request $request, Submission $submission)
    {
        if ($request->query->has('fetch')) {
            /** @var SubmissionFileWithSourceCode $file */
            $file = $this->entityManager->createQueryBuilder()
                ->from('DOMJudgeBundle:SubmissionFileWithSourceCode', 'file')
                ->select('file')
                ->andWhere('file.rank = :rank')
                ->andWhere('file.submission = :submission')
                ->setParameter(':rank', $request->query->get('fetch'))
                ->setParameter(':submission', $submission)
                ->getQuery()
                ->getOneOrNullResult();
            if (!$file) {
                throw new NotFoundHttpException(sprintf('No submission file found with rank %s',
                                                        $request->query->get('fetch')));
            }
            // Download requested
            $response = new Response();
            $response->headers->set('Content-Type',
                                    sprintf('text/plain; name="%s"; charset="utf-8"', $file->getFilename()));
            $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $file->getFilename()));
            $response->headers->set('Content-Length', (string)strlen($file->getSourcecode()));
            $response->setContent($file->getSourcecode());

            return $response;
        }

        /** @var SubmissionFileWithSourceCode[] $files */
        $files = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:SubmissionFileWithSourceCode', 'file')
            ->select('file')
            ->andWhere('file.submission = :submission')
            ->setParameter(':submission', $submission)
            ->orderBy('file.rank')
            ->getQuery()
            ->getResult();

        $originalSubmission = $originallFiles = null;

        if ($submission->getOrigsubmitid()) {
            /** @var Submission $originalSubmission */
            $originalSubmission = $this->entityManager->getRepository(Submission::class)->find($submission->getOrigsubmitid());

            /** @var SubmissionFileWithSourceCode[] $files */
            $originallFiles = $this->entityManager->createQueryBuilder()
                ->from('DOMJudgeBundle:SubmissionFileWithSourceCode', 'file')
                ->select('file')
                ->andWhere('file.submission = :submission')
                ->setParameter(':submission', $originalSubmission)
                ->orderBy('file.rank')
                ->getQuery()
                ->getResult();

            /** @var Submission $oldSubmission */
            $oldSubmission = $this->entityManager->createQueryBuilder()
                ->from('DOMJudgeBundle:Submission', 's')
                ->select('s')
                ->andWhere('s.probid = :probid')
                ->andWhere('s.langid = :langid')
                ->andWhere('s.submittime < :submittime')
                ->andWhere('s.origsubmitid = :origsubmitid')
                ->setParameter(':probid', $submission->getProbid())
                ->setParameter(':langid', $submission->getLangid())
                ->setParameter(':submittime', $submission->getSubmittime())
                ->setParameter(':origsubmitid', $submission->getOrigsubmitid())
                ->orderBy('s.submittime', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        } else {
            $oldSubmission = $this->entityManager->createQueryBuilder()
                ->from('DOMJudgeBundle:Submission', 's')
                ->select('s')
                ->andWhere('s.teamid = :teamid')
                ->andWhere('s.probid = :probid')
                ->andWhere('s.langid = :langid')
                ->andWhere('s.submittime < :submittime')
                ->setParameter(':teamid', $submission->getTeamid())
                ->setParameter(':probid', $submission->getProbid())
                ->setParameter(':langid', $submission->getLangid())
                ->setParameter(':submittime', $submission->getSubmittime())
                ->orderBy('s.submittime', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        /** @var SubmissionFileWithSourceCode[] $files */
        $oldFiles = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:SubmissionFileWithSourceCode', 'file')
            ->select('file')
            ->andWhere('file.submission = :submission')
            ->setParameter(':submission', $oldSubmission)
            ->orderBy('file.rank')
            ->getQuery()
            ->getResult();

        $oldFileStats      = $oldFiles !== null ? $this->determineFileChanged($files, $oldFiles) : [];
        $originalFileStats = $originallFiles !== null ? $this->determineFileChanged($files, $originallFiles) : [];

        return $this->render('@DOMJudge/jury/submission_source.html.twig', [
            'submission' => $submission,
            'files' => $files,
            'oldSubmission' => $oldSubmission,
            'oldFiles' => $oldFiles,
            'oldFileStats' => $oldFileStats,
            'originalSubmission' => $originalSubmission,
            'originalFiles' => $originallFiles,
            'originalFileStats' => $originalFileStats,
        ]);
    }

    /**
     * @Route("/submissions/{submission}/edit-source", name="jury_submission_edit_source")
     */
    public function editSourceAction(Request $request, Submission $submission)
    {
        if (!$this->DOMJudgeService->getUser()->getTeam() || !$this->DOMJudgeService->checkrole('team')) {
            throw new BadRequestHttpException('You cannot re-submit code without being a team.');
        }

        /** @var SubmissionFileWithSourceCode[] $files */
        $files = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:SubmissionFileWithSourceCode', 'file')
            ->select('file')
            ->andWhere('file.submission = :submission')
            ->setParameter(':submission', $submission)
            ->orderBy('file.rank')
            ->getQuery()
            ->getResult();

        $data = [
            'problem' => $submission->getProblem(),
            'language' => $submission->getLanguage(),
            'entry_point' => $submission->getEntryPoint(),
        ];

        foreach ($files as $file) {
            $data['source' . $file->getRank()] = $file->getSourcecode();
        }

        $formBuilder = $this->createFormBuilder($data)
            ->add('problem', EntityType::class, [
                'class' => 'DOMJudgeBundle\Entity\Problem',
                'choice_label' => 'name',
                'query_builder' => function (EntityRepository $er) use ($submission) {
                    return $er->createQueryBuilder('p')
                        ->join('p.contest_problems', 'cp')
                        ->andWhere('cp.allow_submit = 1')
                        ->andWhere('cp.contest = :contest')
                        ->setParameter(':contest', $submission->getContest())
                        ->orderBy('p.name');
                },
            ])
            ->add('language', EntityType::class, [
                'class' => 'DOMJudgeBundle\Entity\Language',
                'choice_label' => 'name',
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('lang')
                        ->andWhere('lang.allow_submit = 1')
                        ->orderBy('lang.name');
                }
            ])
            ->add('entry_point', TextType::class, [
                'label' => 'Optional entry point',
                'required' => false,
            ])
            ->add('submit', SubmitType::class);

        foreach ($files as $file) {
            $formBuilder->add('source' . $file->getRank(), TextareaType::class);
        }

        $form = $formBuilder->getForm();

        // Handle the form if it is submitted
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $submittedData = $form->getData();

            /** @var UploadedFile[] $filesToSubmit */
            $filesToSubmit = [];
            foreach ($files as $file) {
                if (!($tmpfname = tempnam(TMPDIR, "edit_source-"))) {
                    throw new ServiceUnavailableHttpException("Could not create temporary file.");
                }
                file_put_contents($tmpfname, $submittedData['source' . $file->getRank()]);
                $filesToSubmit[] = new UploadedFile($tmpfname, $file->getFilename(), null, null, null, true);
            }

            $team = $this->DOMJudgeService->getUser()->getTeam();
            /** @var Language $language */
            $language   = $submittedData['language'];
            $entryPoint = $submittedData['entry_point'];
            if ($language->getRequireEntryPoint() && $entryPoint === null) {
                $entryPoint = '__auto__';
            }
            $submittedSubmission = $this->submissionService->submitSolution(
                $team,
                $submittedData['problem'],
                $submission->getContest(),
                $language,
                $filesToSubmit,
                $submission->getOrigsubmitid() ?? $submission->getSubmitid(),
                $entryPoint
            );

            foreach ($filesToSubmit as $file) {
                unlink($file->getRealPath());
            }

            return $this->redirectToRoute('jury_submission', ['submitId' => $submittedSubmission->getSubmitid()]);
        }

        return $this->render('@DOMJudge/jury/submission_edit_source.html.twig', [
            'submission' => $submission,
            'files' => $files,
            'form' => $form->createView(),
            'selected' => $request->query->get('rank'),
        ]);
    }

    /**
     * @Route("/submissions/{submitId}/update-status", name="jury_submission_update_status", methods={"POST"})
     * @Security("has_role('ROLE_ADMIN')")
     * @param EventLogService   $eventLogService
     * @param ScoreboardService $scoreboardService
     * @param Request           $request
     * @param int               $submitId
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Exception
     */
    public function updateStatusAction(
        EventLogService $eventLogService,
        ScoreboardService $scoreboardService,
        Request $request,
        int $submitId
    ) {
        $submission = $this->entityManager->getRepository(Submission::class)->find($submitId);
        $valid      = $request->request->getBoolean('valid');
        $submission->setValid($valid);
        $this->entityManager->flush();

        // KLUDGE: We can't log an "undelete", so we re-"create".
        // FIXME: We should also delete/recreate any dependent judging(runs).
        $eventLogService->log('submission', $submission->getSubmitid(), ($valid ? 'create' : 'delete'),
                              $submission->getCid());
        $this->DOMJudgeService->auditlog('submission', $submission->getSubmitid(),
                                         'marked ' . ($valid ? 'valid' : 'invalid'));
        $contest = $this->entityManager->getRepository(Contest::class)->find($submission->getCid());
        $team    = $this->entityManager->getRepository(Team::class)->find($submission->getTeamid());
        $problem = $this->entityManager->getRepository(Problem::class)->find($submission->getProbid());
        $scoreboardService->calculateScoreRow($contest, $team, $problem);

        return $this->redirectToRoute('jury_submission', ['submitId' => $submission->getSubmitid()]);
    }

    /**
     * @Route("/submissions/{judgingId}/verify", name="jury_judging_verify", methods={"POST"})
     * @param EventLogService   $eventLogService
     * @param ScoreboardService $scoreboardService
     * @param BalloonService    $balloonService
     * @param Request           $request
     * @param int               $judgingId
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     * @throws \Doctrine\ORM\ORMException
     */
    public function verifyAction(
        EventLogService $eventLogService,
        ScoreboardService $scoreboardService,
        BalloonService $balloonService,
        Request $request,
        int $judgingId
    ) {
        $this->entityManager->transactional(function () use ($eventLogService, $request, $judgingId) {
            /** @var Judging $judging */
            $judging  = $this->entityManager->getRepository(Judging::class)->find($judgingId);
            $verified = $request->request->getBoolean('verified');
            $comment  = $request->request->get('comment');
            $judging
                ->setVerified($verified)
                ->setJuryMember($verified ? $this->DOMJudgeService->getUser()->getUsername() : null)
                ->setVerifyComment($comment);

            $this->entityManager->flush();
            $this->DOMJudgeService->auditlog('judging', $judging->getJudgingid(),
                                             $verified ? 'set verified' : 'set unverified');

            if ((bool)$this->DOMJudgeService->dbconfig_get('verification_required', false)) {
                // Log to event table (case of no verification required is handled
                // in the REST API API/JudgehostController::addJudgingRunAction
                $eventLogService->log('judging', $judging->getJudgingid(), 'update', $judging->getCid());
            }
        });

        if ((bool)$this->DOMJudgeService->dbconfig_get('verification_required', false)) {
            $this->entityManager->clear();
            /** @var Judging $judging */
            $judging = $this->entityManager->getRepository(Judging::class)->find($judgingId);
            $scoreboardService->calculateScoreRow($judging->getContest(), $judging->getSubmission()->getTeam(),
                                                  $judging->getSubmission()->getProblem());
            $balloonService->updateBalloons($judging->getContest(), $judging->getSubmission(), $judging);
        }

        // Redirect to referrer page after verification or back to submission page when unverifying.
        if ($request->request->getBoolean('verified')) {
            $redirect = $request->request->get('redirect', $this->generateUrl('jury_submissions'));
        } else {
            $redirect = $this->generateUrl('jury_submission_by_judging', ['jid' => $judgingId]);
        }

        return $this->redirect($redirect);
    }

    /**
     * @param SubmissionFileWithSourceCode[] $files
     * @param SubmissionFileWithSourceCode[] $oldFiles
     * @return array
     */
    protected function determineFileChanged(array $files, array $oldFiles)
    {
        $result = [
            'added' => [],
            'removed' => [],
            'changed' => [],
            'changedfiles' => [], // These will be shown, so we will add pairs of files here
            'unchanged' => [],
        ];

        $newFilenames = [];
        $oldFilenames = [];
        foreach ($files as $newfile) {
            $oldFilenames = [];
            foreach ($oldFiles as $oldFile) {
                if ($newfile->getFilename() === $oldFile->getFilename()) {
                    if ($oldFile->getSourcecode() === $newfile->getSourcecode()) {
                        $result['unchanged'][] = $newfile->getFilename();
                    } else {
                        $result['changed'][]      = $newfile->getFilename();
                        $result['changedfiles'][] = [$newfile, $oldFile];
                    }
                }
                $oldFilenames[] = $oldFile->getFilename();
            }
            $newFilenames[] = $newfile->getFilename();
        }

        $result['added']   = array_diff($newFilenames, $oldFilenames);
        $result['removed'] = array_diff($oldFilenames, $newFilenames);

        // Special case: if we have exactly one file now and before but the filename is different, use that for diffing
        if (count($result['added']) === 1 && count($result['removed']) === 1 && empty($result['changed'])) {
            $result['added']        = [];
            $result['removed']      = [];
            $result['changed']      = [$files[0]->getFilename()];
            $result['changedfiles'] = [[$files[0], $oldFiles[0]]];
        }

        return $result;
    }
}
