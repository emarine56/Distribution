<?php

namespace Innova\CollecticielBundle\Controller;

use Claroline\CoreBundle\Entity\User;
use Innova\CollecticielBundle\Entity\Comment;
use Innova\CollecticielBundle\Entity\CommentRead;
use Innova\CollecticielBundle\Entity\Correction;
use Innova\CollecticielBundle\Entity\Document;
use Innova\CollecticielBundle\Entity\Drop;
use Innova\CollecticielBundle\Entity\Dropzone;
use Innova\CollecticielBundle\Entity\Grade;
use Innova\CollecticielBundle\Event\Log\LogCommentCreateEvent;
use Innova\CollecticielBundle\Event\Log\LogCommentReadCreateEvent;
use Innova\CollecticielBundle\Event\Log\LogCorrectionDeleteEvent;
use Innova\CollecticielBundle\Event\Log\LogCorrectionEndEvent;
use Innova\CollecticielBundle\Event\Log\LogCorrectionReportEvent;
use Innova\CollecticielBundle\Event\Log\LogCorrectionStartEvent;
use Innova\CollecticielBundle\Event\Log\LogCorrectionUpdateEvent;
use Innova\CollecticielBundle\Event\Log\LogCorrectionValidationChangeEvent;
use Innova\CollecticielBundle\Event\Log\LogDropGradeAvailableEvent;
use Innova\CollecticielBundle\Event\Log\LogDropzoneAddCommentEvent;
use Innova\CollecticielBundle\Form\CommentType;
use Innova\CollecticielBundle\Form\CorrectionCommentType;
use Innova\CollecticielBundle\Form\CorrectionCriteriaPageType;
use Innova\CollecticielBundle\Form\CorrectionDenyType;
use Innova\CollecticielBundle\Form\CorrectionStandardType;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Exception\NotValidCurrentPageException;
use Pagerfanta\Pagerfanta;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class CorrectionController extends DropzoneBaseController
{
    private function checkRightToCorrect($dropzone, $user)
    {
        $em = $this->getDoctrine()->getManager();
        // Check that the dropzone is in the process of peer review
        if (false === $dropzone->isPeerReview()) {
            $this->getRequest()->getSession()->getFlashBag()->add(
                'error',
                $this->get('translator')->trans('The peer review is not enabled', [], 'innova_collecticiel')
            );

            return $this->redirect(
                $this->generateUrl(
                    'innova_collecticiel_open',
                    [
                        'resourceId' => $dropzone->getId(),
                    ]
                )
            );
        }

        // Check that the user has a finished dropzone for this drop.
        $userDrop = $em->getRepository('InnovaCollecticielBundle:Drop')
            ->findOneBy(
                [
                    'user' => $user,
                    'dropzone' => $dropzone,
                    'finished' => true,
                ]
            );
        if (null === $userDrop) {
            $this->getRequest()->getSession()->getFlashBag()->add(
                'error',
                $this->get('translator')->trans('You must have made ​​your copy before correcting', [], 'innova_collecticiel')
            );

            return $this->redirect(
                $this->generateUrl(
                    'innova_collecticiel_open',
                    [
                        'resourceId' => $dropzone->getId(),
                    ]
                )
            );
        }

        // Check that the user still make corrections
        $nbCorrection = $em->getRepository('InnovaCollecticielBundle:Correction')->countFinished($dropzone, $user);
        if ($nbCorrection >= $dropzone->getExpectedTotalCorrection()) {
            $this->getRequest()->getSession()->getFlashBag()->add(
                'error',
                $this->get('translator')->trans('You no longer have any copies to correct', [], 'innova_collecticiel')
            );

            return $this->redirect(
                $this->generateUrl(
                    'innova_collecticiel_open',
                    [
                        'resourceId' => $dropzone->getId(),
                    ]
                )
            );
        }

        return;
    }

    private function getCorrection($dropzone, $user)
    {
        $em = $this->getDoctrine()->getManager();
        // Check that the user as a not finished correction (exclude admin correction). Otherwise generate a new one.
        $correction = $em->getRepository('InnovaCollecticielBundle:Correction')->getNotFinished($dropzone, $user);
        if (null === $correction) {
            $drop = $em->getRepository('InnovaCollecticielBundle:Drop')->drawDropForCorrection($dropzone, $user);

            if (null !== $drop) {
                $correction = new Correction();
                $correction->setDrop($drop);
                $correction->setUser($user);
                $correction->setFinished(false);
                $correction->setDropzone($dropzone);

                $em->persist($correction);
                $em->flush();

                $event = new LogCorrectionStartEvent($dropzone, $drop, $correction);
                $this->dispatch($event);
            }
        } else {
            $correction->setLastOpenDate(new \DateTime());
            $em->persist($correction);
            $em->flush();
        }

        return $correction;
    }

    private function getCriteriaPager($dropzone)
    {
        $em = $this->getDoctrine()->getManager();
        $criterionRepository = $em->getRepository('InnovaCollecticielBundle:Criterion');
        $criterionQuery = $criterionRepository
            ->createQueryBuilder('criterion')
            ->andWhere('criterion.dropzone = :dropzone')
            ->setParameter('dropzone', $dropzone)
            ->orderBy('criterion.id', 'ASC');

        $adapter = new DoctrineORMAdapter($criterionQuery);
        $pager = new Pagerfanta($adapter);
        $pager->setMaxPerPage(DropzoneBaseController::CRITERION_PER_PAGE);

        return $pager;
    }

    private function persistGrade($grades, $criterionId, $value, $correction)
    {
        $em = $this->getDoctrine()->getManager();

        $grade = null;
        $i = 0;
        while ($i < count($grades) && null === $grade) {
            $current = $grades[$i];
            if (
                $current->getCriterion()->getId() === $criterionId
                && $current->getCorrection()->getId() === $correction->getId()
            ) {
                $grade = $current;
            }
            ++$i;
        }

        if (null === $grade) {
            $criterionReference = $em->getReference('InnovaCollecticielBundle:Criterion', $criterionId);
            $grade = new Grade();
            $grade->setCriterion($criterionReference);
            $grade->setCorrection($correction);
        }
        $grade->setValue($value);
        $em->persist($grade);
        $em->flush();

        return $grade;
    }

    private function endCorrection(Dropzone $dropzone, Correction $correction, $admin)
    {
        $em = $this->getDoctrine()->getManager();

        $edit = false;
        if (true === $correction->getFinished()) {
            $edit = true;
        }

        $drop = $correction->getDrop();
        $correction->setEndDate(new \DateTime());
        $correction->setFinished(true);
        $totalGrade = $this->get('innova.manager.correction_manager')->calculateCorrectionTotalGrade($dropzone, $correction);
        $correction->setTotalGrade($totalGrade);

        $em->persist($correction);
        $em->flush();

        $event = null;
        if (true === $edit) {
            $event = new LogCorrectionUpdateEvent($dropzone, $correction->getDrop(), $correction);
        } else {
            $event = new LogCorrectionEndEvent($dropzone, $correction->getDrop(), $correction);
        }
        $this->dispatch($event);

        $this->getRequest()->getSession()->getFlashBag()->add(
            'success',
            $this->get('translator')->trans('Your correction has been saved', [], 'innova_collecticiel')
        );

        // check if the drop owner can now access to his grade.
        $this->checkUserGradeAvailableByDrop($drop);

        if (true === $admin) {
            return $this->redirect(
                $this->generateUrl(
                    'innova_collecticiel_drops_detail',
                    [
                        'resourceId' => $dropzone->getId(),
                        'dropId' => $correction->getDrop()->getId(),
                    ]
                )
            );
        } else {
            return $this->redirect(
                $this->generateUrl(
                    'innova_collecticiel_open',
                    [
                        'resourceId' => $dropzone->getId(),
                    ]
                )
            );
        }
    }

    private function checkUserGradeAvailableByDrop(Drop $drop)
    {
        $user = $drop->getUser();
        $dropzone = $drop->getDropzone();
        $this->checkUserGradeAvailable($dropzone, $drop, $user);
    }

    /**
     * Check the user's drop to see if he has corrected enought copy and if his copy is fully corrected
     * in order to notify him that his grade is available.
     * */
    private function checkUserGradeAvailable(Dropzone $dropzone, Drop $drop, $user)
    {
        // notification only in the PeerReview mode.
        $em = $this->getDoctrine()->getManager();
        $event = new LogDropGradeAvailableEvent($dropzone, $drop);
        if (1 === $dropzone->getPeerReview()) {
            // copy corrected by user

            // corrections on the user's copy
            $nbCorrectionByOthersOnUsersCopy = $em->getRepository('InnovaCollecticielBundle:Correction')->getCorrectionsIds($dropzone, $drop);

            //Expected corrections
            $expectedCorrections = $dropzone->getExpectedTotalCorrection();

            // corrected copy only instead of corrected copy AND given corrections.
            if (count($nbCorrectionByOthersOnUsersCopy) >= $expectedCorrections) {
                //dispatchEvent.
                $this->get('event_dispatcher')->dispatch('log', $event);
            }
        } else {
            $nbCorrectionByOthersOnUsersCopy = $em->getRepository('InnovaCollecticielBundle:Correction')
                ->getCorrectionsIds($dropzone, $drop);

            if ($nbCorrectionByOthersOnUsersCopy > 0) {
                $this->get('event_dispatcher')->dispatch('log', $event);
            }
        }
    }

    /**
     * @Route(
     *      "/{resourceId}/correct",
     *      name="innova_collecticiel_correct",
     *      requirements={"resourceId" = "\d+"},
     *      defaults={"page" = 1}
     * )
     * @Route(
     *      "/{resourceId}/correct/{page}",
     *      name="innova_collecticiel_correct_paginated",
     *      requirements={"resourceId" = "\d+", "page" = "\d+"},
     *      defaults={"page" = 1}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("user", options={
     *      "authenticatedUser" = true,
     *      "messageEnabled" = true,
     *      "messageTranslationKey" = "Correct an evaluation requires authentication. Please login.",
     *      "messageTranslationDomain" = "innova_collecticiel"
     * })
     * @Template()
     */
    public function correctAction($dropzone, $user, $page)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $em = $this->getDoctrine()->getManager();

        $check = $this->checkRightToCorrect($dropzone, $user);
        if (null !== $check) {
            return $check;
        }

        $correction = $this->getCorrection($dropzone, $user);
        if (null === $correction) {
            $this->getRequest()->getSession()->getFlashBag()->add(
                'error',
                $this
                    ->get('translator')
                    ->trans('Unfortunately there is no copy to correct for the moment. Please try again later', [], 'innova_collecticiel')
            );

            return $this->redirect(
                $this->generateUrl(
                    'innova_collecticiel_open',
                    [
                        'resourceId' => $dropzone->getId(),
                    ]
                )
            );
        }

        $pager = $this->getCriteriaPager($dropzone);
        try {
            $pager->setCurrentPage($page);
        } catch (NotValidCurrentPageException $e) {
            throw new NotFoundHttpException();
        }

        $oldData = [];
        $grades = [];
        if (null !== $correction) {
            $grades = $em
                ->getRepository('InnovaCollecticielBundle:Grade')
                ->findByCriteriaAndCorrection($pager->getCurrentPageResults(), $correction);
            foreach ($grades as $grade) {
                $oldData[$grade->getCriterion()->getId()] = ($grade->getValue() >= $dropzone->getTotalCriteriaColumn())
                    ? ($dropzone->getTotalCriteriaColumn() - 1) : $grade->getValue();
            }
        }

        $form = $this->createForm(
            new CorrectionCriteriaPageType(),
            $oldData,
            [
                'criteria' => $pager->getCurrentPageResults(),
                'totalChoice' => $dropzone->getTotalCriteriaColumn(),
            ]
        );

        if ($this->getRequest()->isMethod('POST') && null !== $correction) {
            $form->handleRequest($this->getRequest());
            if ($form->isValid()) {
                $data = $form->getData();

                foreach ($data as $criterionId => $value) {
                    $this->persistGrade($grades, $criterionId, $value, $correction);
                }

                $goBack = $form->get('goBack')->getData();
                if (1 === $goBack) {
                    $pageNumber = max(($page - 1), 0);

                    return $this->redirect(
                        $this->generateUrl(
                            'innova_collecticiel_correct_paginated',
                            [
                                'resourceId' => $dropzone->getId(),
                                'page' => $pageNumber,
                            ]
                        )
                    );
                } else {
                    if ($pager->getCurrentPage() < $pager->getNbPages()) {
                        return $this->redirect(
                            $this->generateUrl(
                                'innova_collecticiel_correct_paginated',
                                [
                                    'resourceId' => $dropzone->getId(),
                                    'page' => ($page + 1),
                                ]
                            )
                        );
                    } else {
                        return $this->redirect(
                            $this->generateUrl(
                                'innova_collecticiel_correct_comment',
                                [
                                    'resourceId' => $dropzone->getId(),
                                ]
                            )
                        );
                    }
                }
            }
        }

        $dropzoneManager = $this->get('innova.manager.dropzone_manager');
        $dropzoneProgress = $dropzoneManager->getDropzoneProgressByUser($dropzone, $user);

        $view = 'InnovaCollecticielBundle:Correction:correctCriteria.html.twig';

        return $this->render(
            $view,
            [
                'workspace' => $dropzone->getResourceNode()->getWorkspace(),
                '_resource' => $dropzone,
                'dropzone' => $dropzone,
                'correction' => $correction,
                'pager' => $pager,
                'form' => $form->createView(),
                'admin' => false,
                'edit' => true,
                'dropzoneProgress' => $dropzoneProgress,
            ]
        );
    }

    /**
     * @Route(
     *      "/{resourceId}/correct/comment",
     *      name="innova_collecticiel_correct_comment",
     *      requirements={"resourceId" = "\d+"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("user", options={
     *      "authenticatedUser" = true,
     *      "messageEnabled" = true,
     *      "messageTranslationKey" = "Correct an evaluation requires authentication. Please login.",
     *      "messageTranslationDomain" = "innova_collecticiel"
     * })
     * @Template()
     */
    public function correctCommentAction(Dropzone $dropzone, User $user)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $check = $this->checkRightToCorrect($dropzone, $user);
        if (null !== $check) {
            return $check;
        }

        $correction = $this->getCorrection($dropzone, $user);
        if (null === $correction) {
            $this
                ->getRequest()
                ->getSession()
                ->getFlashBag()
                ->add(
                    'error',
                    $this
                        ->get('translator')
                        ->trans('Unfortunately there is no copy to correct for the moment. Please try again later', [], 'innova_collecticiel')
                );

            return $this->redirect(
                $this->generateUrl(
                    'innova_collecticiel_open',
                    [
                        'resourceId' => $dropzone->getId(),
                    ]
                )
            );
        }

        $pager = $this->getCriteriaPager($dropzone);
        $form = $this->createForm(
                    new CorrectionCommentType(),
                    $correction,
                    [
                        'allowCommentInCorrection' => $dropzone->getAllowCommentInCorrection(),
                    ]
                );

        if ($this->getRequest()->isMethod('POST')) {
            $form->handleRequest($this->getRequest());
            if ($form->isValid()) {
                $correction = $form->getData();

                if ($dropzone->getForceCommentInCorrection() && '' === $correction->getComment()) {
                    // field is required and not filled
                    $this
                        ->getRequest()
                        ->getSession()
                        ->getFlashBag()
                        ->add(
                            'error',
                            $this
                                ->get('translator')
                                ->trans('The comment field is required please let a comment', [], 'innova_collecticiel')
                        );

                    return $this->redirect(
                        $this->generateUrl(
                            'innova_collecticiel_correct_comment',
                            [
                                'resourceId' => $dropzone->getId(),
                            ]
                        )
                    );
                }
                $em = $this->getDoctrine()->getManager();
                $em->persist($correction);
                $em->flush();

                $goBack = $form->get('goBack')->getData();
                if (1 === $goBack) {
                    return $this->redirect(
                        $this->generateUrl(
                            'innova_collecticiel_correct_paginated',
                            [
                                'resourceId' => $dropzone->getId(),
                                'page' => $pager->getNbPages(),
                            ]
                        )
                    );
                } else {
                    return $this->endCorrection($dropzone, $correction, false);
                }
            }
        }

        $view = 'InnovaCollecticielBundle:Correction:correctComment.html.twig';

        $totalGrade = $this->get('innova.manager.correction_manager')->calculateCorrectionTotalGrade($dropzone, $correction);

        $dropzoneManager = $this->get('innova.manager.dropzone_manager');
        $dropzoneProgress = $dropzoneManager->getDropzoneProgressByUser($dropzone, $user);

        return $this->render(
            $view,
            [
                'workspace' => $dropzone->getResourceNode()->getWorkspace(),
                '_resource' => $dropzone,
                'dropzone' => $dropzone,
                'correction' => $correction,
                'form' => $form->createView(),
                'nbPages' => $pager->getNbPages(),
                'admin' => false,
                'edit' => true,
                'totalGrade' => $totalGrade,
                'dropzoneProgress' => $dropzoneProgress,
            ]
        );
    }

    /**
     * @Route(
     *      "/{resourceId}/drops/detail/correction/standard/{state}/{correctionId}/{backUserId}",
     *      name="innova_collecticiel_drops_detail_correction_standard",
     *      requirements={"resourceId" = "\d+", "correctionId" = "\d+", "state" = "show|edit", "backUserId" = "\d+"},
     *      defaults={"backUserId" = "-1"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("user", options={
     *      "authenticatedUser" = true,
     *      "messageEnabled" = true,
     *      "messageTranslationKey" = "Correct an evaluation requires authentication. Please login.",
     *      "messageTranslationDomain" = "innova_collecticiel"
     * })
     */
    public function dropsDetailCorrectionStandardAction(Dropzone $dropzone, $state, $correctionId, $user, $backUserId)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        /** @var Correction $correction */
        $correction = $this
            ->getDoctrine()
            ->getRepository('InnovaCollecticielBundle:Correction')
            ->getCorrectionAndDropAndUserAndDocuments($dropzone, $correctionId);

        $edit = 'edit' === $state;

        if (true === $edit && false === $correction->getEditable()) {
            throw new AccessDeniedException();
        }

        $form = $this->createForm(new CorrectionStandardType(), $correction);

        if ($this->getRequest()->isMethod('POST')) {
            $form->handleRequest($this->getRequest());
            if ($form->isValid()) {
                $em = $this->getDoctrine()->getManager();

                $event = null;
                if (true === $correction->getFinished()) {
                    $event = new LogCorrectionUpdateEvent($dropzone, $correction->getDrop(), $correction);
                } else {
                    $event = new LogCorrectionEndEvent($dropzone, $correction->getDrop(), $correction);
                }

                $correction = $form->getData();
                $correction->setEndDate(new \DateTime());
                $correction->setFinished(true);

                $em->persist($correction);
                $em->flush();

                $this->dispatch($event);

                $event = new LogDropGradeAvailableEvent($dropzone, $correction->getDrop());
                $this->get('event_dispatcher')->dispatch('log', $event);

                $this->getRequest()->getSession()->getFlashBag()->add(
                    'success',
                    $this->get('translator')->trans('Your correction has been saved', [], 'innova_collecticiel')
                );

                return $this->redirect(
                    $this->generateUrl(
                        'innova_collecticiel_drops_detail',
                        [
                            'resourceId' => $dropzone->getId(),
                            'dropId' => $correction->getDrop()->getId(),
                        ]
                    )
                );
            }
        }

        $view = 'InnovaCollecticielBundle:Correction:correctStandard.html.twig';

        return $this->render(
            $view,
            [
                'workspace' => $dropzone->getResourceNode()->getWorkspace(),
                '_resource' => $dropzone,
                'dropzone' => $dropzone,
                'correction' => $correction,
                'form' => $form->createView(),
                'admin' => true,
                'edit' => $edit,
                'state' => $state,
                'backUserId' => $backUserId,
            ]
        );
    }

    /**
     * @Route(
     *      "/{resourceId}/drops/detail/correction/{state}/{correctionId}/document/{documentId}",
     *      name="innova_collecticiel_drops_detail_comment",
     *      requirements={"documentId" = "\d+", "resourceId" = "\d+", "correctionId" = "\d+", "state" = "show|edit|preview"},
     *      defaults={"page" = 1}
     * )
     * @Route(
     *      "/{resourceId}/drops/detail/correction/{state}/{correctionId}/{page}",
     *      name="innova_collecticiel_drops_detail_correction_paginated",
     *      requirements={"resourceId" = "\d+", "correctionId" = "\d+", "page" = "\d+", "state" = "show|edit|preview"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("document", class="InnovaCollecticielBundle:Document", options={"id" = "documentId"})
     * @ParamConverter("user", options={
     *      "authenticatedUser" = true,
     *      "messageEnabled" = true,
     *      "messageTranslationKey" = "Correct an evaluation requires authentication. Please login.",
     *      "messageTranslationDomain" = "innova_collecticiel"
     * })
     * @Template()
     */
    public function dropsDetailCommentAction(Dropzone $dropzone, $state, $correctionId, $page, User $user, Document $document)
    {
        $documentOri = new Document();
        $documentOri = $document;

        $em = $this->getDoctrine()->getManager();
        $dropzoneManager = $this->get('innova.manager.dropzone_manager');
        $collecticielOpenOrNot = $dropzoneManager->collecticielOpenOrNot($dropzone);

        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $correction = $this
            ->getDoctrine()
            ->getRepository('InnovaCollecticielBundle:Correction')
            ->getCorrectionAndDropAndUserAndDocuments($dropzone, $correctionId);

        // Parcours des documents sélectionnés
        foreach ($correction->getDrop()->getDocuments() as $document) {
            if ($document->getId() === $documentOri->getId()) {
                $documentId = $document->getId();

                // Ajout pour avoir les commentaires et qui les a lu.
                // Lire les commentaires et les passer à la vue
                $comments = $this
                        ->getDoctrine()
                        ->getRepository('InnovaCollecticielBundle:Comment')
                        ->findBy(
                            [
                                'document' => $documentId,
                            ]
                        );

                // Parcours des commentaires des documents sélectionnés
                foreach ($comments as $comment) {
                    $commentId = $comment->getId();

                    $comments_read = $this
                            ->getDoctrine()
                            ->getRepository('InnovaCollecticielBundle:CommentRead')
                            ->findBy(
                                [
                                    'comment' => $commentId,
                                    'user' => $correction->getUser()->getId(),
                                ]
                            );

                    // Nombre de lectures du commentaire pour cet utilisateur pour ce commentaire du document
                    $countCommentRead = count($comments_read);

                    // Ce commentaire n'avait pas été lu. Donc, maintenant, il va l'être,
                    // je dois donc insérer une occurrence dans la table CommentRead";
                    if (0 === ($countCommentRead)) {
                        $comment_read_add = new CommentRead();
                        $comment_read_add->setComment($comment);
                        $comment_read_add->setUser($user);

                        $em->persist($comment_read_add);

                        $unitOfWork = $em->getUnitOfWork();
                        $unitOfWork->computeChangeSets();
                        $dropzoneChangeSet = $unitOfWork->getEntityChangeSet($dropzone);

                        $event = new LogCommentReadCreateEvent($dropzone, $dropzoneChangeSet, $comment_read_add);

                        $this->dispatch($event);
                    }
                }
                $em->flush();
                // Fin ajout.
            }
        }

        $userId = $this->get('security.token_storage')->getToken()->getUser()->getId();
        if ('preview' === $state) {
            if ($correction->getDrop()->getUser()->getId() !== $userId) {
                throw new AccessDeniedException();
            }
        }

        $edit = 'edit' === $state;

        if (null === $correction) {
            throw new NotFoundHttpException();
        }

        if (true === $edit && false === $correction->getEditable()) {
            throw new AccessDeniedException();
        }

        $em = $this->getDoctrine()->getManager();

        $pager = $this->getCriteriaPager($dropzone);
        try {
            $pager->setCurrentPage($page);
        } catch (NotValidCurrentPageException $e) {
            throw new NotFoundHttpException();
        }

        $oldData = [];
        $grades = [];
        if (null !== $correction) {
            $grades = $em
                ->getRepository('InnovaCollecticielBundle:Grade')
                ->findByCriteriaAndCorrection($pager->getCurrentPageResults(), $correction);
            foreach ($grades as $grade) {
                $oldData[$grade->getCriterion()->getId()] = ($grade->getValue() >= $dropzone->getTotalCriteriaColumn())
                    ? ($dropzone->getTotalCriteriaColumn() - 1) : $grade->getValue();
            }
        }

        $formComment = $this->createForm(
            new CommentType(new Comment(), null))
        ;

        $form = $this->createForm(
            new CorrectionCriteriaPageType(),
            $oldData,
            [
                'edit' => $edit,
                'criteria' => $pager->getCurrentPageResults(),
                'totalChoice' => $dropzone->getTotalCriteriaColumn(),
            ]
        );
        if ($edit) {
            if ($this->getRequest()->isMethod('POST') && null !== $correction) {
                $form->handleRequest($this->getRequest());
                if ($form->isValid()) {
                    $data = $form->getData();

                    foreach ($data as $criterionId => $value) {
                        $this->persistGrade($grades, $criterionId, $value, $correction);
                    }

                    if ($correction->getFinished()) {
                        $totalGrade = $this->get('innova.manager.correction_manager')->calculateCorrectionTotalGrade($dropzone, $correction);
                        $correction->setTotalGrade($totalGrade);

                        $em->persist($correction);
                        $em->flush();
                    }
                    $goBack = $form->get('goBack')->getData();
                    if (1 === $goBack) {
                        $pageNumber = max(($page - 1), 0);

                        return $this->redirect(
                            $this->generateUrl(
                                'innova_collecticiel_drops_detail_correction_paginated',
                                [
                                    'resourceId' => $dropzone->getId(),
                                    'state' => 'edit',
                                    'correctionId' => $correction->getId(),
                                    'page' => $pageNumber,
                                ]
                            )
                        );
                    } else {
                        if ($pager->getCurrentPage() < $pager->getNbPages()) {
                            return $this->redirect(
                                $this->generateUrl(
                                    'innova_collecticiel_drops_detail_correction_paginated',
                                    [
                                        'resourceId' => $dropzone->getId(),
                                        'state' => 'edit',
                                        'correctionId' => $correction->getId(),
                                        'page' => ($page + 1),
                                    ]
                                )
                            );
                        } else {
                            return $this->redirect(
                                $this->generateUrl(
                                    'innova_collecticiel_drops_detail_correction_comment',
                                    [
                                        'resourceId' => $dropzone->getId(),
                                        'state' => 'edit',
                                        'correctionId' => $correction->getId(),
                                    ]
                                )
                            );
                        }
                    }
                }
            }
        }

        $dropzoneVoter = $this->get('innova.manager.dropzone_voter');
        $canEdit = $dropzoneVoter->checkEditRight($dropzone);

        // Appel de la vue qui va gérer l'ajout des commentaires. InnovaERV.
        $view = 'InnovaCollecticielBundle:Correction:correctCriteria.html.twig';

        if ('show' === $state || 'edit' === $state) {
            return $this->render(
                $view,
                [
                    'workspace' => $dropzone->getResourceNode()->getWorkspace(),
                    '_resource' => $dropzone,
                    'dropzone' => $dropzone,
                    'correction' => $correction,
                    'pager' => $pager,
                    'form' => $form->createView(),
                    'formComment' => $formComment->createView(),
                    'admin' => true,
                    'edit' => $edit,
                    'state' => $state,
                    'document' => $documentOri,
                    'comments' => $comments,
                    'user' => $user,
                    'adminInnova' => $canEdit,
                    'collecticielOpenOrNot' => $collecticielOpenOrNot,
                ]
            );
        } elseif ('preview' === $state) {
            return $this->render(
                $view,
                [
                    'workspace' => $dropzone->getResourceNode()->getWorkspace(),
                    '_resource' => $dropzone,
                    'dropzone' => $dropzone,
                    'correction' => $correction,
                    'pager' => $pager,
                    'form' => $form->createView(),
                    'formComment' => $formComment->createView(),
                    'admin' => false,
                    'edit' => false,
                    'state' => $state,
                    'adminInnova' => $canEdit,
                    'collecticielOpenOrNot' => $collecticielOpenOrNot,
                ]
            );
        }
    }

    /**
     * @Route(
     *      "/{resourceId}/drops/detail/correction/comment/{state}/{correctionId}",
     *      name="innova_collecticiel_drops_detail_correction_comment",
     *      requirements={"resourceId" = "\d+", "correctionId" = "\d+", "state" = "show|edit|preview"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("user", options={
     *      "authenticatedUser" = true,
     *      "messageEnabled" = true,
     *      "messageTranslationKey" = "Correct an evaluation requires authentication. Please login.",
     *      "messageTranslationDomain" = "innova_collecticiel"
     * })
     * @Template()
     */
    public function dropsDetailCorrectionCommentAction(Dropzone $dropzone, $state, $correctionId, $user)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        if ('preview' !== $state) {
            $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);
        }

        $correction = $this
            ->getDoctrine()
            ->getRepository('InnovaCollecticielBundle:Correction')
            ->getCorrectionAndDropAndUserAndDocuments($dropzone, $correctionId);
        $edit = 'edit' === $state;

        if (true === $edit && false === $correction->getEditable()) {
            throw new AccessDeniedException();
        }

        $pager = $this->getCriteriaPager($dropzone);
        $form = $this->createForm(
            new CorrectionCommentType(),
            $correction,
            [
                'edit' => $edit,
                'allowCommentInCorrection' => $dropzone->getAllowCommentInCorrection(),
            ]
        );

        if ($edit) {
            if ($this->getRequest()->isMethod('POST')) {
                $form->handleRequest($this->getRequest());
                if ($form->isValid()) {
                    $em = $this->getDoctrine()->getManager();
                    $correction = $form->getData();
                    $em->persist($correction);
                    $em->flush();

                    $goBack = $form->get('goBack')->getData();
                    if (1 === $goBack) {
                        return $this->redirect(
                            $this->generateUrl(
                                'innova_collecticiel_drops_detail_correction_paginated',
                                [
                                    'resourceId' => $dropzone->getId(),
                                    'state' => 'edit',
                                    'correctionId' => $correction->getId(),
                                    'page' => $pager->getNbPages(),
                                ]
                            )
                        );
                    } else {
                        return $this->endCorrection($dropzone, $correction, true);
                    }
                }
            }

            $view = 'InnovaCollecticielBundle:Correction:correctComment.html.twig';
            $totalGrade = $this->get('innova.manager.correction_manager')->calculateCorrectionTotalGrade($dropzone, $correction);

            return $this->render(
                $view,
                [
                    'workspace' => $dropzone->getResourceNode()->getWorkspace(),
                    '_resource' => $dropzone,
                    'dropzone' => $dropzone,
                    'correction' => $correction,
                    'form' => $form->createView(),
                    'nbPages' => $pager->getNbPages(),
                    'admin' => true,
                    'edit' => $edit,
                    'state' => $state,
                    'totalGrade' => $totalGrade,
                ]
            );
        }

        $view = 'InnovaCollecticielBundle:Correction:correctComment.html.twig';

        if ('show' === $state) {
            $totalGrade = $this->get('innova.manager.correction_manager')->calculateCorrectionTotalGrade($dropzone, $correction);

            return $this->render(
                $view,
                [
                    'workspace' => $dropzone->getResourceNode()->getWorkspace(),
                    '_resource' => $dropzone,
                    'dropzone' => $dropzone,
                    'correction' => $correction,
                    'form' => $form->createView(),
                    'nbPages' => $pager->getNbPages(),
                    'admin' => true,
                    'edit' => $edit,
                    'state' => $state,
                    'totalGrade' => $totalGrade,
                ]
            );
        } elseif ('preview' === $state) {
            $totalGrade = $correction->getTotalGrade();

            return $this->render(
                $view,
                [
                    'workspace' => $dropzone->getResourceNode()->getWorkspace(),
                    '_resource' => $dropzone,
                    'dropzone' => $dropzone,
                    'correction' => $correction,
                    'form' => $form->createView(),
                    'nbPages' => $pager->getNbPages(),
                    'admin' => false,
                    'edit' => false,
                    'state' => $state,
                    'totalGrade' => $totalGrade,
                ]
            );
        }
    }

    /**
     * @Route(
     *      "/{resourceId}/drops/detail/{dropId}/add/comments/document/{documentId}",
     *      name="innova_collecticiel_drops_detail_add_comments_innova",
     *      requirements={"resourceId" = "\d+", "dropId" = "\d+", "documentId" = "\d+"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("document", class="InnovaCollecticielBundle:Document", options={"id" = "documentId"})
     * @ParamConverter("user", options={
     *      "authenticatedUser" = true,
     *      "messageEnabled" = true,
     *      "messageTranslationKey" = "Correct an evaluation requires authentication. Please login.",
     *      "messageTranslationDomain" = "innova_collecticiel"
     * })
     * @ParamConverter("drop", class="InnovaCollecticielBundle:Drop", options={"id" = "dropId"})
     * @Template()
     */
    public function dropsDetailAddCommentsInnovaAction($dropzone, $user, $drop, $document)
    {
        $em = $this->getDoctrine()->getManager();
        $correction = new Correction();
        $correction->setUser($user);
        $correction->setDropzone($dropzone);
        $correction->setDrop($drop);
        //Allow admins to edit this correction
        $correction->setEditable(true);
        $em->persist($correction);
        $em->flush();

        $event = new LogCorrectionStartEvent($dropzone, $drop, $correction);
        $this->dispatch($event);

        // L'URL est déclarée dans le controlleur. InnovaERV.
        return $this->redirect(
            $this->generateUrl(
                'innova_collecticiel_drops_detail_comment',
                [
                    'resourceId' => $dropzone->getId(),
                    'state' => 'edit',
                    'correctionId' => $correction->getId(),
                    'documentId' => $document->getId(),
                ]
            )
        );
    }

    /**
     * @Route(
     *      "/{resourceId}/delete/correction/{correctionId}/{backPage}",
     *      name="innova_collecticiel_drops_detail_delete_correction",
     *      requirements={"resourceId" = "\d+", "correctionId" = "\d+"},
     *      defaults={"backPage" = "default"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("correction", class="InnovaCollecticielBundle:Correction", options={"id" = "correctionId"})
     * @Template()
     */
    public function deleteCorrectionAction(Dropzone $dropzone, Correction $correction, $backPage)
    {
        $userId = $correction->getUser()->getId();
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        if (false === $correction->getEditable()) {
            throw new AccessDeniedException();
        }

        $dropId = $correction->getDrop()->getId();

        // Action on POST , real delete
        if ($this->getRequest()->isMethod('POST')) {
            $em = $this->getDoctrine()->getManager();
            $em->remove($correction);
            $em->flush();

            $event = new LogCorrectionDeleteEvent($dropzone, $correction->getDrop(), $correction);
            $this->dispatch($event);

            $return = null;
            if ('AdminCorrectionsByUser' === $backPage) {
                $return = $this->redirect(
                    $this->generateUrl(
                        'innova_collecticiel_drops_detail',
                        [
                            'resourceId' => $dropzone->getId(),
                            'dropId' => $dropId,
                        ]
                    )
                );
            } else {
                $return = $this->redirect(
                    $this->generateUrl(
                        'innova_collecticiel_examiner_corrections',
                        [
                            'resourceId' => $dropzone->getId(),
                            'userId' => $userId,
                        ]
                    )
                );
            }
        } else {
            // Action on GET , Ask confirmation Modal or not.

            $view = 'InnovaCollecticielBundle:Correction:deleteCorrection.html.twig';
            $backUserId = 0;

            $backUserId = $this->getRequest()->get('backUserId');
            if ($this->getRequest()->isXmlHttpRequest()) {
                $view = 'InnovaCollecticielBundle:Correction:deleteCorrectionModal.html.twig';
                $backUserId = $correction->getUser()->getId();
            }

            $return = $this->render(
                $view,
                [
                    'workspace' => $dropzone->getResourceNode()->getWorkspace(),
                    '_resource' => $dropzone,
                    'dropzone' => $dropzone,
                    'correction' => $correction,
                    'drop' => $correction->getDrop(),
                    'backPage' => 'AdminCorrectionsByUser',
                    'backUserId' => $backUserId,
                ]
            );
        }

        return $return;
    }

    /**
     * @Route(
     *      "/{resourceId}/drops/detail/correction/validation/confirmation/{correctionId}/{value}",
     *      name="innova_collecticiel_revalidateCorrection",
     *      requirements ={"resourceId" ="\d+","withDropOnly"="^(withDropOnly|all|withoutDrops)$"},
     *      defaults={"page" = 1, "withDropOnly" = "all", "value"="yes" }
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("correction", class="InnovaCollecticielBundle:Correction", options={"id" = "correctionId"})
     * @Template()
     */
    public function RevalidateCorrectionValidationAction(Dropzone $dropzone, Correction $correction, $value)
    {
        // check if number of correction will be more than the expected.

        // only valid corrections are count
        if ($dropzone->getExpectedTotalCorrection() <= $correction->getDrop()->countFinishedCorrections()) {
            // Ask confirmation to have more correction than expected.
            $view = 'InnovaCollecticielBundle:Correction:Admin/revalidateCorrection.html.twig';
            if ($this->getRequest()->isXmlHttpRequest()) {
                $view = 'InnovaCollecticielBundle:Correction:Admin/revalidateCorrectionModal.html.twig';
            }

            return $this->render(
                $view,
                [
                    '_resource' => $dropzone,
                    'dropzone' => $dropzone,
                    'drop' => $correction->getDrop(),
                    'correction' => $correction,
                ]
            );
        } else {
            $this->setCorrectionValidationAction($dropzone, $correction, 'yes', 'default');

            return $this->redirect(
                $this->generateUrl(
                    'innova_collecticiel_drops_detail',
                    [
                        'resourceId' => $dropzone->getId(),
                        'dropId' => $correction->getDrop()->getId(),
                    ]
                )
            );
        }
    }

    /**
     * @Route(
     *      "/{resourceId}/drops/detail/correction/validation/{value}/{correctionId}",
     *      name="innova_collecticiel_drops_detail_correction_validation",
     *      requirements={"resourceId" = "\d+", "correctionId" = "\d+", "value" = "no|yes"},
     *      defaults={"routeParam"="default"}
     * )
     * @Route(
     *      "/{resourceId}/drops/detail/correction/validation/byUser/{value}/{correctionId}",
     *      name="innova_collecticiel_drops_detail_correction_validation_by_user",
     *      requirements={"resourceId" = "\d+", "correctionId" = "\d+", "value" = "no|yes"},
     *      defaults={"routeParam"="byUser"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("correction", class="InnovaCollecticielBundle:Correction", options={"id" = "correctionId"})
     * @Template()
     */
    public function setCorrectionValidationAction(Dropzone $dropzone, Correction $correction, $value, $routeParam)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        $em = $this->getDoctrine()->getManager();

        if ('yes' === $value) {
            $correction->setValid(true);
            $correction->setFinished(true);
        } else {
            $correction->setValid(false);
        }

        $em->persist($correction);
        $em->flush();

        $event = new LogCorrectionValidationChangeEvent($dropzone, $correction->getDrop(), $correction);
        $this->dispatch($event);

        //Notify user his copy has an available note
        $this->checkUserGradeAvailableByDrop($correction->getDrop());

        if ('default' === $routeParam) {
            return $this->redirect(
                $this->generateUrl(
                    'innova_collecticiel_drops_detail',
                    [
                        'resourceId' => $dropzone->getId(),
                        'dropId' => $correction->getDrop()->getId(),
                    ]
                )
            );
        } elseif ('byUser' === $routeParam) {
            return $this->redirect(
                $this->generateUrl(
                    'innova_collecticiel_examiner_corrections',
                    [
                        'resourceId' => $dropzone->getId(),
                        'userId' => $correction->getUser()->getId(),
                    ]
                )
            );
        }
    }

    /**
     * @Route(
     *      "/{resourceId}/drops/detail/{dropId}/invalidate_all",
     *      name="innova_collecticiel_drops_detail_invalidate_all_corrections",
     *      requirements={"resourceId" = "\d+", "dropId" = "\d+"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("drop", class="InnovaCollecticielBundle:Drop", options={"id" = "dropId"})
     * @Template()
     */
    public function invalidateAllCorrectionsAction($dropzone, $drop)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        $this
            ->getDoctrine()
            ->getRepository('InnovaCollecticielBundle:Correction')
            ->invalidateAllCorrectionForADrop($dropzone, $drop);

        //TODO invalidate all correction event

        return $this->redirect(
            $this->generateUrl(
                'innova_collecticiel_drops_detail',
                [
                    'resourceId' => $dropzone->getId(),
                    'dropId' => $drop->getId(),
                ]
            )
        );
    }

    /**
     * @Route("/{resourceId}/drops/detail/correction/deny/{correctionId}",
     * name="innova_collecticiel_drops_deny_correction",
     * requirements={"resourceId" = "\d+","correctionId" = "\d+"})
     *
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("correction", class="InnovaCollecticielBundle:Correction", options={"id" = "correctionId"})
     **/
    public function denyCorrectionAction($dropzone, $correction)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $form = $this->createForm(new CorrectionDenyType(), $correction);

        $dropUser = $correction->getDrop()->getUser();
        $dropId = $correction->getDrop()->getId();
        $dropzoneId = $dropzone->getId();
        // dropZone not in peerReview or corrections are not displayed to users or correction deny is not allowed
        if (!$dropzone->getPeerReview() || !$dropzone->getAllowCorrectionDeny() || !$dropzone->getDiplayCorrectionsToLearners()) {
            throw new AccessDeniedException();
        }
        // if loggued user is not the drop owner and is not admin.
        if (false === $this->get('security.authorization_checker')->isGranted('ROLE_ADMIN') && $this->get('security.token_storage')->getToken()->getUser()->getId() !== $dropUser->getId()) {
            throw new AccessDeniedException();
        }

        if ($this->getRequest()->isMethod('POST')) {
            $form->handleRequest($this->getRequest());
            if ($form->isValid()) {
                $correction->setCorrectionDenied(true);
                $em = $this->getDoctrine()->getManager();
                $em->persist($correction);
                $em->flush();

                $this->dispatchCorrectionReportEvent($dropzone, $correction);
                $this
                    ->getRequest()
                    ->getSession()
                    ->getFlashBag()
                    ->add('success', $this->get('translator')->trans('Your report has been saved', [], 'innova_collecticiel'));

                return $this->redirect(
                    $this->generateUrl(
                        'innova_collecticiel_drop_detail_by_user',
                        [
                            'resourceId' => $dropzoneId,
                            'dropId' => $dropId,
                        ]
                    )
                );
            }
        }

        // not a post, she show the view.
        $view = 'InnovaCollecticielBundle:Correction:reportCorrection.html.twig';

        if ($this->getRequest()->isXmlHttpRequest()) {
            $view = 'InnovaCollecticielBundle:Correction:reportCorrectionModal.html.twig';
        }

        return $this->render(
            $view,
            [
                'workspace' => $dropzone->getResourceNode()->getWorkspace(),
                '_resource' => $dropzone,
                'dropzone' => $dropzone,
                'drop' => $correction->getDrop(),
                'correction' => $correction,
                'form' => $form->createView(),
            ]
        );
    }

    protected function dispatchCorrectionReportEvent(Dropzone $dropzone, Correction $correction)
    {
        $drop = $correction->getDrop();
        $rm = $this->get('claroline.manager.role_manager');
        $event = new LogCorrectionReportEvent($dropzone, $drop, $correction, $rm);
        $this->get('event_dispatcher')->dispatch('log', $event);
    }

    /**
     * @Route(
     *      "/{resourceId}/recalculate/score/{correctionId}",
     *      name="innova_collecticiel_recalculate_score",
     *      requirements={"resourceId" = "\d+", "correctionId" = "\d+"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("correction", class="InnovaCollecticielBundle:Correction", options={"id" = "correctionId"})
     * @Template()
     */
    public function recalculateScoreAction(Dropzone $dropzone, Correction $correction)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        if (!$dropzone->getPeerReview()) {
            throw new AccessDeniedException();
        }

        $oldTotalGrade = $correction->getTotalGrade();

        $totalGrade = $this->get('innova.manager.correction_manager')->calculateCorrectionTotalGrade($dropzone, $correction);
        $correction->setTotalGrade($totalGrade);
        $em = $this->getDoctrine()->getManager();

        $em->persist($correction);
        $em->flush();

        if ($oldTotalGrade !== $totalGrade) {
            $event = new LogCorrectionUpdateEvent($dropzone, $correction->getDrop(), $correction);
            $this->dispatch($event);
        }

        return $this->redirect(
            $this->generateUrl(
                'innova_collecticiel_drops_detail',
                [
                    'resourceId' => $dropzone->getId(),
                    'dropId' => $correction->getDrop()->getId(),
                ]
            )
        );
    }

    /**
     * @Route(
     *      "/{resourceId}/examiners/{userId}",
     *      name="innova_collecticiel_examiner_corrections",
     *      requirements ={"resourceId" ="\d+","userId"="\d+"},
     *      defaults={"page" = 1 }
     * )
     *
     * @Route(
     *      "/{resourceId}/examiners/{userId}/{page}",
     *      name="innova_collecticiel_examiner_corrections_paginated",
     *      requirements ={"resourceId" ="\d+","userId"="\d+","page"="\d+"},
     *      defaults={"page" = 1 }
     * )
     *
     *
     * @ParamConverter("dropzone",class="InnovaCollecticielBundle:Dropzone",options={"id" = "resourceId"})
     * @ParamConverter("user",class="ClarolineCoreBundle:User",options={"id" = "userId"})
     * @Template()
     * **/
    public function correctionsByUserAction(Dropzone $dropzone, User $user, $page)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        $correctionsQuery = $this->getDoctrine()->getManager()
            ->getRepository('InnovaCollecticielBundle:Correction')
            ->getByDropzoneUser($dropzone->getId(), $user->getId(), true);

        $adapter = new DoctrineORMAdapter($correctionsQuery);
        $pager = new Pagerfanta($adapter);
        $pager->setMaxPerPage(DropzoneBaseController::CORRECTION_PER_PAGE);
        try {
            $pager->setCurrentPage($page);
        } catch (NotValidCurrentPageException $e) {
            if ($page > 0) {
                return $this->redirect(
                    $this->generateUrl(
                        'innova_collecticiel_examiner_corrections_paginated',
                        [
                            'resourceId' => $dropzone->getId(),
                            'userId' => $user->getId(),
                        ]
                    )
                );
            } else {
                throw new NotFoundHttpException();
            }
        }
        $corrections = $pager->getCurrentPageResults();

        return [
                '_resource' => $dropzone,
                'dropzone' => $dropzone,
                'pager' => $pager,
                'user' => $user,
                'corrections' => $corrections,
            ];
    }

    /**
     * @Route(
     *      "/{resourceId}/examiners/{withDropOnly}",
     *      name="innova_collecticiel_examiners",
     *      requirements ={"resourceId" ="\d+","withDropOnly"="^(withDropOnly|all|withoutDrops)$"},
     *      defaults={"page" = 1, "withDropOnly" = "all" }
     * )
     *
     * @Route(
     *      "/{resourceId}/examiners/{withDropOnly}/{page}",
     *      name="innova_collecticiel_examiners_paginated",
     *      requirements ={"resourceId" ="\d+","withDropOnly"="^(withDropOnly|all|withoutDrops)$","page"="\d+"},
     *      defaults={"page" = 1, "withDropOnly" = "all" }
     * )
     *
     *
     * @ParamConverter("dropzone",class="InnovaCollecticielBundle:Dropzone",options={"id" = "resourceId"})
     * @Template()
     * **/
    public function ExaminersByCorrectionMadeAction($dropzone, $page, $withDropOnly)
    {
        // check rights
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        //getting the repos
        $dropRepo = $this->getDoctrine()->getManager()->getRepository('InnovaCollecticielBundle:Drop');
        $countUnterminatedDrops = $dropRepo->countUnterminatedDropsByDropzone($dropzone->getId());
        $correctionRepo = $this->getDoctrine()->getManager()->getRepository('InnovaCollecticielBundle:Correction');

        // getting the Query of  users that have at least one correction.
        $usersQuery = $correctionRepo->getUsersByDropzoneQuery($dropzone);

        // pagitation management.
        $adapter = new DoctrineORMAdapter($usersQuery);
        $pager = new Pagerfanta($adapter);
        $pager->setMaxPerPage(DropzoneBaseController::DROP_PER_PAGE);

        try {
            $pager->setCurrentPage($page);
        } catch (NotValidCurrentPageException $e) {
            if ($page > 0) {
                return $this->redirect(
                    $this->generateUrl(
                        'innova_collecticiel_examiners_paginated',
                        [
                            'resourceId' => $dropzone->getId(),
                            'page' => $pager->getNbPages(),
                        ]
                    )
                );
            } else {
                throw new NotFoundHttpException();
            }
        }

        // execute the query and get the users.
        $users = $usersQuery->getResult();
        // add some count needed by the view.
        $usersAndCorrectionCount = $this->addCorrectionCount($dropzone, $users);

        $response = [
            'workspace' => $dropzone->getResourceNode()->getWorkspace(),
            '_resource' => $dropzone,
            'dropzone' => $dropzone,
            'usersAndCorrectionCount' => $usersAndCorrectionCount,
            'nbDropCorrected' => $dropRepo->countDropsFullyCorrected($dropzone),
            'nbDrop' => $dropRepo->countDrops($dropzone),
            'unterminated_drops' => $countUnterminatedDrops,
            'pager' => $pager,
        ];

        return $this->render(
            'InnovaCollecticielBundle:Drop:Examiners/ExaminersByName.htlm.twig',
            $response
        );
    }

    private function addCorrectionCount(Dropzone $dropzone, $users)
    {
        $correctionRepo = $this->getDoctrine()->getManager()->getRepository('InnovaCollecticielBundle:Correction');
        $dropRepo = $this->getDoctrine()->getManager()->getRepository('InnovaCollecticielBundle:Drop');
        $response = [];
        foreach ($users as $user) {
            $responseItem = [];
            $responseItem['userId'] = $user->getId();
            $corrections = $correctionRepo->getByDropzoneUser($dropzone->getId(), $user->getId());
            $isUnlockedDrop = $dropRepo->isUnlockedDrop($dropzone->getId(), $user->getId());
            $count = count($corrections);
            $responseItem['correction_count'] = $count;

            $finishedCount = 0;
            $reportsCount = 0;
            $deniedCount = 0;
            foreach ($corrections as $correction) {
                if ($correction->getCorrectionDenied()) {
                    ++$deniedCount;
                }
                if ($correction->getReporter()) {
                    ++$reportsCount;
                }
                if ($correction->getFinished()) {
                    ++$finishedCount;
                }
            }

            $responseItem['correction_deniedCount'] = $deniedCount;
            $responseItem['correction_reportCount'] = $reportsCount;
            $responseItem['correction_finishedCount'] = $finishedCount;
            $responseItem['drop_isUnlocked'] = $isUnlockedDrop;
            $response[$user->getId()] = $responseItem;
        }

        return $response;
    }

    /**
     * @Route(
     *      "/{dropId}/recalculateDropGrade",
     *      name="innova_collecticiel_recalculate_drop_grade",
     *      requirements={"dropId" = "\d+"}
     * )
     * @ParamConverter("drop", class="InnovaCollecticielBundle:Drop", options={"id" = "dropId"})
     */
    public function recalculateScoreByDropAction($drop)
    {
        $dropzone = $drop->getDropzone();
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        if (!$dropzone->getPeerReview()) {
            throw new AccessDeniedException();
        }
        // getting the repository
        $CorrectionRepo = $this->getDoctrine()->getManager()->getRepository('InnovaCollecticielBundle:Correction');
        // getting all the drop corrections
        $corrections = $CorrectionRepo->findBy(['drop' => $drop->getId()]);

        $this->recalculateScoreForCorrections($dropzone, $corrections);

        return $this->redirect(
            $this->generateUrl(
                'innova_collecticiel_drops_detail',
                [
                    'resourceId' => $dropzone->getId(),
                    'dropId' => $drop->getId(),
                ]
            )
        );
    }

    /**
     * @Route(
     *      "/{dropzone}/recalculateDropzoneGrades",
     *      name="innova_collecticiel_recalculate_dropzone_grades",
     *      requirements={"dropId" = "\d+"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "dropzone"})
     */
    public function recalculateScoreByDropzoneAction($dropzone)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        $this->get('icap.dropzone_manager')->recalculateScoreByDropzone($dropzone);

        return $this->redirect(
            $this->generateUrl(
                'innova_collecticiel_edit_criteria',
                [
                    'resourceId' => $dropzone->getId(),
                ]
            )
        );
    }

    private function recalculateScoreForCorrections(Dropzone $dropzone, array $corrections)
    {
        // recalculate the score for all corrections
        foreach ($corrections as $correction) {
            $oldTotalGrade = $correction->getTotalGrade();
            $totalGrade = $this->get('innova.manager.correction_manager')->calculateCorrectionTotalGrade($dropzone, $correction);
            $correction->setTotalGrade($totalGrade);
            $em = $this->getDoctrine()->getManager();

            $em->persist($correction);

            $currentDrop = $correction->getDrop();
            if (null !== $currentDrop && $oldTotalGrade !== $totalGrade) {
                $event = new LogCorrectionUpdateEvent($dropzone, $currentDrop, $correction);
                $this->dispatch($event);
            }
        }
        $em->flush();
    }

    /**
     * @Route(
     *      "/{resourceId}/drops/detail/{dropId}/add/correction",
     *      name="innova_collecticiel_drops_detail_add_correction",
     *      requirements={"resourceId" = "\d+", "dropId" = "\d+"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("user", options={
     *      "authenticatedUser" = true,
     *      "messageEnabled" = true,
     *      "messageTranslationKey" = "Correct an evaluation requires authentication. Please login.",
     *      "messageTranslationDomain" = "innova_collecticiel"
     * })
     * @ParamConverter("drop", class="InnovaCollecticielBundle:Drop", options={"id" = "dropId"})
     * @Template()
     */
    public function dropsDetailAddCorrectionAction($dropzone, $user, $drop)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        $em = $this->getDoctrine()->getManager();
        $correction = new Correction();
        $correction->setUser($user);
        $correction->setDropzone($dropzone);
        $correction->setDrop($drop);
        //Allow admins to edit this correction
        $correction->setEditable(true);
        $em->persist($correction);
        $em->flush();

        $event = new LogCorrectionStartEvent($dropzone, $drop, $correction);
        $this->dispatch($event);

        return $this->redirect(
            $this->generateUrl(
                'innova_collecticiel_drops_detail_correction',
                [
                    'resourceId' => $dropzone->getId(),
                    'state' => 'edit',
                    'correctionId' => $correction->getId(),
                ]
            )
        );
    }

    /**
     * @Route(
     *      "/{resourceId}/drops/detail/correction/{state}/{correctionId}",
     *      name="innova_collecticiel_drops_detail_add_comments",
     *      requirements={"resourceId" = "\d+", "correctionId" = "\d+", "state" = "show|edit|preview"},
     *      defaults={"page" = 1}
     * )
     * @Route(
     *      "/{resourceId}/drops/detail/correction/{state}/{correctionId}/{page}",
     *      name="innova_collecticiel_drops_detail_correction_paginated_comments",
     *      requirements={"resourceId" = "\d+", "correctionId" = "\d+", "page" = "\d+", "state" = "show|edit|preview"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("user", options={
     *      "authenticatedUser" = true,
     *      "messageEnabled" = true,
     *      "messageTranslationKey" = "Correct an evaluation requires authentication. Please login.",
     *      "messageTranslationDomain" = "innova_collecticiel"
     * })
     * @Template()
     */
    public function dropsDetailAddCommentsAction(Dropzone $dropzone, $state, $correctionId, $page, $user)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $correction = $this
            ->getDoctrine()
            ->getRepository('InnovaCollecticielBundle:Correction')
            ->getCorrectionAndDropAndUserAndDocuments($dropzone, $correctionId);
        $userId = $this->get('security.token_storage')->getToken()->getUser()->getId();
        if ('preview' === $state) {
            if ($correction->getDrop()->getUser()->getId() !== $userId) {
                throw new AccessDeniedException();
            }
        } else {
            $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);
        }

        if (!$dropzone->getPeerReview()) {
            return $this->redirect(
                $this->generateUrl(
                    'innova_collecticiel_drops_detail_correction_standard',
                    [
                        'resourceId' => $dropzone->getId(),
                        'state' => $state,
                        'correctionId' => $correctionId,
                    ]
                )
            );
        }

        /* @var Correction $correction */

        $edit = 'edit' === $state;

        if (null === $correction) {
            throw new NotFoundHttpException();
        }

        if (true === $edit && false === $correction->getEditable()) {
            throw new AccessDeniedException();
        }

        $em = $this->getDoctrine()->getManager();

        $pager = $this->getCriteriaPager($dropzone);
        try {
            $pager->setCurrentPage($page);
        } catch (NotValidCurrentPageException $e) {
            throw new NotFoundHttpException();
        }

        $oldData = [];
        $grades = [];
        if (null !== $correction) {
            $grades = $em
                ->getRepository('InnovaCollecticielBundle:Grade')
                ->findByCriteriaAndCorrection($pager->getCurrentPageResults(), $correction);
            foreach ($grades as $grade) {
                $oldData[$grade->getCriterion()->getId()] = ($grade->getValue() >= $dropzone->getTotalCriteriaColumn())
                    ? ($dropzone->getTotalCriteriaColumn() - 1) : $grade->getValue();
            }
        }

        $form = $this->createForm(
            new CorrectionCriteriaPageType(),
            $oldData,
            [
                'edit' => $edit,
                'criteria' => $pager->getCurrentPageResults(),
                'totalChoice' => $dropzone->getTotalCriteriaColumn(),
            ]
        );
        if ($edit) {
            if ($this->getRequest()->isMethod('POST') && null !== $correction) {
                $form->handleRequest($this->getRequest());
                if ($form->isValid()) {
                    $data = $form->getData();

                    foreach ($data as $criterionId => $value) {
                        $this->persistGrade($grades, $criterionId, $value, $correction);
                    }

                    if ($correction->getFinished()) {
                        $totalGrade = $this->get('innova.manager.correction_manager')->calculateCorrectionTotalGrade($dropzone, $correction);
                        $correction->setTotalGrade($totalGrade);

                        $em->persist($correction);
                        $em->flush();
                    }
                    $goBack = $form->get('goBack')->getData();
                    if (1 === $goBack) {
                        $pageNumber = max(($page - 1), 0);

                        return $this->redirect(
                            $this->generateUrl(
                                'innova_collecticiel_drops_detail_correction_paginated',
                                [
                                    'resourceId' => $dropzone->getId(),
                                    'state' => 'edit',
                                    'correctionId' => $correction->getId(),
                                    'page' => $pageNumber,
                                ]
                            )
                        );
                    } else {
                        if ($pager->getCurrentPage() < $pager->getNbPages()) {
                            return $this->redirect(
                                $this->generateUrl(
                                    'innova_collecticiel_drops_detail_correction_paginated',
                                    [
                                        'resourceId' => $dropzone->getId(),
                                        'state' => 'edit',
                                        'correctionId' => $correction->getId(),
                                        'page' => ($page + 1),
                                    ]
                                )
                            );
                        } else {
                            return $this->redirect(
                                $this->generateUrl(
                                    'innova_collecticiel_drops_detail_correction_comment',
                                    [
                                        'resourceId' => $dropzone->getId(),
                                        'state' => 'edit',
                                        'correctionId' => $correction->getId(),
                                    ]
                                )
                            );
                        }
                    }
                }
            }
        }

        $view = 'InnovaCollecticielBundle:Correction:correctCriteria.html.twig';

        if ('show' === $state || 'edit' === $state) {
            return $this->render(
                $view,
                [
                    'workspace' => $dropzone->getResourceNode()->getWorkspace(),
                    '_resource' => $dropzone,
                    'dropzone' => $dropzone,
                    'correction' => $correction,
                    'pager' => $pager,
                    'form' => $form->createView(),
                    'admin' => true,
                    'edit' => $edit,
                    'state' => $state,
                ]
            );
        } elseif ('preview' === $state) {
            return $this->render(
                $view,
                [
                    'workspace' => $dropzone->getResourceNode()->getWorkspace(),
                    '_resource' => $dropzone,
                    'dropzone' => $dropzone,
                    'correction' => $correction,
                    'pager' => $pager,
                    'form' => $form->createView(),
                    'admin' => false,
                    'edit' => false,
                    'state' => $state,
                ]
            );
        }
    }

    /**
     * @Route(
     *      "/{documentId}/add/comments/{userId}/{dropzoneId}/{correctionId}",
     *      name="innova_collecticiel_add_comment",
     *      requirements={"documentId" = "\d+", "userId" = "\d+", "dropzoneId" = "\d+", "correctionId" = "\d+"}
     * )
     * @ParamConverter("document", class="InnovaCollecticielBundle:Document", options={"id" = "documentId"})
     * @ParamConverter("user",class="ClarolineCoreBundle:User",options={"id" = "userId"})
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "dropzoneId"})
     * @ParamConverter("correction", class="InnovaCollecticielBundle:Correction", options={"id" = "correctionId"})
     * @Method("POST")
     * @Template()
     */
    public function AddCommentsInnovaAction(Document $document, User $user, Dropzone $dropzone, Correction $correction)
    {
        $em = $this->getDoctrine()->getManager();

        // Valorisation du commentaire
        $comment = new Comment();
        $comment->setDocument($document);
        $comment->setUser($user);

        $form = $this->get('form.factory')->createBuilder(new CommentType(), $comment)->getForm();

        // Récupération de la saisie du commentaire
        $request = $this->get('request_stack')->getMasterRequest();

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);

            if ($form->isValid()) {
                $em = $this->getDoctrine()->getManager();

                // Insertion en base du commentaire
                $em->persist($comment);
                $em->flush();

                // Envoi notification. InnovaERV
                $usersIds = [];
                $usersIds[] = $dropzone->getResourceNode()->getCreator()->getId();
                $usersIds[] = $document->getSender()->getId();
                $event = new LogDropzoneAddCommentEvent($dropzone, $dropzone->getManualState(), $usersIds, $comment);
                $this->get('event_dispatcher')->dispatch('log', $event);
            }
        }

        // Ajouter la création du log de la création du commentaire. InnovaERV.
        $unitOfWork = $em->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        $dropzoneChangeSet = $unitOfWork->getEntityChangeSet($dropzone);

        $event = new LogCommentCreateEvent($dropzone, $dropzoneChangeSet, $comment);

        $this->dispatch($event);

        // Redirection vers la page des commentaires. InnovaERV.
        return $this->redirect(
            $this->generateUrl(
                'innova_collecticiel_drops_detail_comment',
                [
                    'resourceId' => $dropzone->getId(),
                    'state' => 'edit',
                    'correctionId' => $correction->getId(),
                    'documentId' => $document->getId(),
                ]
            )
        );
    }

    /**
     * @Route(
     *      "/dropzone/comments",
     *      name="innova_collecticiel_add_more_comments",
     *      options={"expose"=true}
     * )
     * @Template()
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function ajaxDropzoneAddMoreCommentsAction()
    {
        $em = $this->getDoctrine()->getManager();

        // Récupération de l'utilisateur
        $user = $this->get('security.token_storage')->getToken()->getUser();

        // Récupération de l'ID du dropzone choisi
        $dropzoneId = $this->get('request_stack')->getMasterRequest()->query->get('dropzoneId');
        $dropzone = $this->getDoctrine()->getRepository('InnovaCollecticielBundle:Dropzone')->find($dropzoneId);

        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);

        // Récupération des documents sélectionnés
        $arrayDocsId = $this->get('request_stack')->getMasterRequest()->query->get('arrayDocsId');
        $arrayDocsToView = [];

        $arrayDropsId = $this->get('request_stack')->getMasterRequest()->query->get('arrayDropsId');
        $arrayDropsToView = [];

        $cpt = 0;

        // Parcours des documents sélectionnés et insertion en base de données
        if (!empty($arrayDocsToView)) {
            foreach ($arrayDocsId as $documentId) {
                // Par le JS, le document est transmis sous la forme "document_id_XX"
                $docIdS = explode('_', $documentId);
                $docId = $docIdS[2];

                if ($docId > 0) {
                    $arrayDocsToView[] = $docId;

                    $dropId = $arrayDropsId[$cpt];
                    $drop = $this->getDoctrine()->getRepository('InnovaCollecticielBundle:Drop')->find($dropId);
                    $arrayDropsToView[] = $dropId;

                    $correction = new Correction();
                    $correction->setUser($user);
                    $correction->setDropzone($dropzone);
                    $correction->setDrop($drop);
                    //Allow admins to edit this correction
                    $correction->setEditable(true);
                    $em->persist($correction);
                    $em->flush();

                    $event = new LogCorrectionStartEvent($dropzone, $drop, $correction);
                    $this->dispatch($event);
                }
                ++$cpt;
            }
        }

        $edit = 'edit';

        $oldData = [];

        $page = 1;

        $pager = $this->getCriteriaPager($dropzone);
        try {
            $pager->setCurrentPage($page);
        } catch (NotValidCurrentPageException $e) {
            throw new NotFoundHttpException();
        }

        $this->createForm(
            new CommentType(new Comment(), null))
        ;

        $this->createForm(
            new CorrectionCriteriaPageType(),
            $oldData,
            [
                'edit' => $edit,
                'criteria' => $pager->getCurrentPageResults(),
                'totalChoice' => $dropzone->getTotalCriteriaColumn(),
            ]
        );

        $redirectRoot = $this->generateUrl(
            'innova_collecticiel_add_more_comments_view',
            [
                'dropzoneId' => $dropzoneId,
                'arrayDocsId' => $arrayDocsId,
                'arrayDropsId' => $arrayDropsId,
            ]
        );

        return new JsonResponse(
            [
                'link' => $redirectRoot,
            ]
        );
    }

    /**
     * @Route(
     *      "/dropzone/comments/view",
     *      name="innova_collecticiel_add_more_comments_view",
     *      options={"expose"=true}
     * )
     * @Template()
     */
    public function dropzoneAddMoreCommentsAction()
    {
        $dropzoneManager = $this->get('innova.manager.dropzone_manager');

        // Récupération de l'utilisateur
        $user = $this->get('security.token_storage')->getToken()->getUser();

        // Récupération de l'ID du dropzone choisi
        $dropzoneId = $this->get('request_stack')->getMasterRequest()->query->get('dropzoneId');

        $dropzone = $this->getDoctrine()->getRepository('InnovaCollecticielBundle:Dropzone')->find($dropzoneId);

        $collecticielOpenOrNot = $dropzoneManager->collecticielOpenOrNot($dropzone);

        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);

        // Récupération des documents sélectionnés
        $arrayDocsId = $this->get('request_stack')->getMasterRequest()->query->get('arrayDocsId');
        $arrayDocsToView = [];

        $arrayDropsId = $this->get('request_stack')->getMasterRequest()->query->get('arrayDropsId');
        $arrayDropsToView = [];

        $cpt = 0;
        $stringDocsId = '';
        if (!empty($arrayDocsId)) {
            foreach ($arrayDocsId as $documentId) {
                // Par le JS, le document est transmis sous la forme "document_id_XX"
                $docIdS = explode('_', $documentId);
                $docId = $docIdS[2];
                $stringDocsId = $stringDocsId.$docId.'|';

                $document = $this->getDoctrine()->getRepository('InnovaCollecticielBundle:Document')->find($docId);
                $arrayDocsToView[] = $document;

                $dropId = $arrayDropsId[$cpt];
                $drop = $this->getDoctrine()->getRepository('InnovaCollecticielBundle:Drop')->find($dropId);
                $arrayDropsToView[] = $drop;
                ++$cpt;
            }
        }

        $edit = 'edit';
        $state = 'edit';

        $oldData = [];

        $dropzoneVoter = $this->get('innova.manager.dropzone_voter');
        $canEdit = $dropzoneVoter->checkEditRight($dropzone);

        $page = 1;

        $pager = $this->getCriteriaPager($dropzone);
        try {
            $pager->setCurrentPage($page);
        } catch (NotValidCurrentPageException $e) {
            throw new NotFoundHttpException();
        }

        $formComment = $this->createForm(
            new CommentType(new Comment(), null))
        ;

        $form = $this->createForm(
            new CorrectionCriteriaPageType(),
            $oldData,
            [
                'edit' => $edit,
                'criteria' => $pager->getCurrentPageResults(),
                'totalChoice' => $dropzone->getTotalCriteriaColumn(),
            ]
        );

        // Appel de la vue qui va gérer l'ajout des commentaires. InnovaERV.
        $view = 'InnovaCollecticielBundle:Correction:correctAddMoreComments.html.twig';

        return $this->render(
            $view,
            [
                'workspace' => $dropzone->getResourceNode()->getWorkspace(),
                '_resource' => $dropzone,
                'dropzone' => $dropzone,
                'form' => $form->createView(),
                'formComment' => $formComment->createView(),
                'admin' => true,
                'pager' => $pager,
                'edit' => $edit,
                'state' => $state,
                'arrayDocsToView' => $arrayDocsToView,
                'arrayDropsToView' => $arrayDropsToView,
                'stringDocsId' => $stringDocsId,
                'user' => $user,
                'adminInnova' => $canEdit,
                'collecticielOpenOrNot' => $collecticielOpenOrNot,
            ]
        );
    }

    /**
     * @Route(
     *      "/add/more/comments",
     *      name="innova_collecticiel_add_more_comment",
     *      requirements={}
     * )
     * @Method("POST")
     * @Template()
     */
    public function addMoreCommentsInnovaAction()
    {
        $em = $this->getDoctrine()->getManager();

        // Valorisation du commentaire
        $comment = new Comment();

        $form = $this->get('form.factory')->createBuilder(new CommentType(), $comment)->getForm();

        // Récupération de la saisie du commentaire
        $request = $this->get('request_stack')->getMasterRequest();

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);

            if ($form->isValid()) {
                $em = $this->getDoctrine()->getManager();

                // Insertion en base du commentaire
                $em->persist($comment);
                $em->flush();

                // Envoi notification. InnovaERV
                $usersIds = [];
                $usersIds[] = $dropzone->getResourceNode()->getCreator()->getId();
                $usersIds[] = $document->getSender()->getId();
                $event = new LogDropzoneAddCommentEvent($dropzone, $dropzone->getManualState(), $usersIds, $comment);
                $this->get('event_dispatcher')->dispatch('log', $event);
            }
        }

        // Ajouter la création du log de la création du commentaire. InnovaERV.
        $unitOfWork = $em->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        $dropzoneChangeSet = $unitOfWork->getEntityChangeSet($dropzone);

        $event = new LogCommentCreateEvent($dropzone, $dropzoneChangeSet, $comment);

        $this->dispatch($event);

        // Redirection vers la page des commentaires. InnovaERV.
        return $this->redirect(
            $this->generateUrl(
                'innova_collecticiel_drops_detail_comment',
                [
                    'resourceId' => $dropzone->getId(),
                    'state' => 'edit',
                    'documentId' => $document->getId(),
                ]
            )
        );
    }

    /**
     * @Route(
     *      "/add/comments/{userId}/{dropzoneId}",
     *      name="innova_collecticiel_add_comment_for_docs",
     *      requirements={"userId" = "\d+", "dropzoneId" = "\d+"}
     * )
     * @ParamConverter("user",class="ClarolineCoreBundle:User",options={"id" = "userId"})
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "dropzoneId"})
     * @Method("POST")
     * @Template()
     */
    public function addCommentForDocsInnovaAction(User $user, Dropzone $dropzone)
    {
        // Saisie des commentaires à la volée.

        // Récupération de l'USER
        $user = $this->get('security.token_storage')->getToken()->getUser();

        $em = $this->getDoctrine()->getManager();

        $comment = new Comment();
        $form = $this->get('form.factory')->createBuilder(new CommentType(), $comment)->getForm();

        // Récupération de la saisie du commentaire
        $request = $this->get('request_stack')->getMasterRequest();

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);

            if ($form->isValid()) {
                // Récupération du commentaire
                $commentText = $comment->getCommentText();

                // Récupération des documents sur lesquels porte le commentaire saisi
                $docs = $this->getRequest()->request->get('docs');

                // Récupération des ID des documents
                $explodeDocIdS = explode('|', $docs);

                // Parcours du tableau des documents
                foreach ($explodeDocIdS as $documentId) {
                    // Je ne prends pas le dernier élément du tableau
                    if (is_numeric($documentId)) {
                        // Création du nouveau commentaire
                        $document = $this->getDoctrine()->getRepository('InnovaCollecticielBundle:Document')->find($documentId);
                        // Valorisation du commentaire
                        $comment = new Comment();
                        $comment->setDocument($document);
                        $comment->setCommentText($commentText);
                        $comment->setUser($user);
                        // Insertion en base du commentaire
                        $em->persist($comment);

                        // Envoi notification. InnovaERV
                        $usersIds = [];

                        // Ici, on récupère le créateur du collecticiel = l'admin
                        if ('url' === $document->getType()) {
                            $userCreator = $document->getDrop()->getDropzone()->getResourceNode()->getCreator()->getId();
                        } else {
                            $userCreator = $document->getResourceNode()->getCreator()->getId();
                        }

                        // Ici, on récupère celui qui vient de déposer le nouveau document
                        $userDropDocument = $document->getDrop()->getUser()->getId();
                        $userSenderDocument = $document->getSender()->getId();

                        if ($userCreator === $userSenderDocument) {
                            // Ici avertir l'étudiant qui a travaillé sur ce collecticiel
                            $usersIds[] = $userDropDocument;
                        } else {
                            // Ici avertir celui a qui créé le collecticiel
                            $usersIds[] = $userCreator;
                        }

                        $event = new LogDropzoneAddCommentEvent($dropzone, $dropzone->getManualState(), $usersIds, $comment);
                        $this->get('event_dispatcher')->dispatch('log', $event);
                    }
                }

                $em->flush();
            }
        }

        // Ajouter la création du log de la création du commentaire. InnovaERV.
        $unitOfWork = $em->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        $dropzoneChangeSet = $unitOfWork->getEntityChangeSet($dropzone);

        $event = new LogCommentCreateEvent($dropzone, $dropzoneChangeSet, $comment);

        $this->dispatch($event);

        // Redirection vers les demandes adressées. InnovaERV.
        return $this->redirect(
            $this->generateUrl(
                'innova_collecticiel_drops_awaiting',
                [
                    'resourceId' => $dropzone->getId(),
                ]
            )
        );
    }
}
