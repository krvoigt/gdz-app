<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Subugoe\FindBundle\Controller\DefaultController as BaseController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Solarium\QueryType\Select\Query\FilterQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use AppBundle\Entity\DocumentStructure;
use Subugoe\FindBundle\Entity\Search;
use Symfony\Component\Routing\Exception\InvalidParameterException;
use Solarium\QueryType\Select\Query\Query;

class DefaultController extends BaseController
{
    /**
     * @Route("/", name="_homepage")
     *
     * @param Request $request A request instance
     *
     * @return Response
     */
    public function indexAction(Request $request)
    {
        $sort = $request->get('sort');
        if (isset($sort) && empty($sort)) {
            throw new InvalidParameterException(sprintf('Sort argument must be provided.'));
        }
        $order = $request->get('direction');

        $search = $this->getSearchEntity($request);
        $select = $this->getQuerySelect($request);
        $this->addQuerySort($select, $sort, $order);
        $activeFacets = $request->get('filter');
        $this->addQueryFilters($select, $activeFacets);
        $collection = $request->get('collection');
        if (!empty($collection) && $collection !== 'all') {
            $dcFilter = new FilterQuery();
            $collectionFilter = $dcFilter->setKey('dc')->setQuery('dc:'.$collection);
            $select->addFilterQuery($collectionFilter);
        }
        $pagination = $this->getPagination($request, $select);
        $facets = $this->get('solarium.client')->select($select)->getFacetSet()->getFacets();
        $facetCounter = $this->get('subugoe_find.query_service')->getFacetCounter($activeFacets);

        return $this->render('SubugoeFindBundle:Default:index.html.twig', [
                'facets' => $facets,
                'facetCounter' => $facetCounter,
                'queryParams' => $request->get('filter') ?: [],
                'search' => $search,
                'pagination' => $pagination,
                'activeCollection' => $request->get('activeCollection'),
        ]);
    }

    /**
     * @Route("/id/{id}", name="_detail")
     *
     * @param string $id The document id
     *
     * @return Response
     */
    public function detailAction($id)
    {
        $documentStructure = new DocumentStructure();

        $request = $this->get('request_stack')->getCurrentRequest();

        if ($request->get('page')) {
            $page = $request->get('page');
        } else {
            $page = 1;
        }

        $documentId = $id;

        if (strchr($id, '|')) {
            $idArr = explode('|', $id);
            $documentId = $idArr[0];
            if (isset($idArr[1]) && !empty($idArr[1])) {
                $activeChapterId = $idArr[1];
                $previousPageChapterId = $activeChapterId;
                $nextPageChapterId = $activeChapterId;
            }
        }

        $client = $this->get('solarium.client');
        $select = $client->createSelect();
        $select->setQuery('id:'.$documentId);
        $document = $client->select($select);
        $document = $document->getDocuments();
        if (count($document) === 0) {
            throw new NotFoundHttpException(sprintf('Document %s not found', $documentId));
        }

        if (isset($document[0]->presentation_url[0])) {
            $identifier = explode('/', explode('.', $document[0]->presentation_url[0])[3])[3];
            $identifier = $documentId.':'.str_pad($page, strlen($identifier), 0, STR_PAD_LEFT);
        }

        if (!$document[0]->isanchor) {
            $metsService = $this->get('mets_service');
            $pageMappings = $metsService->getScannedPagesMapping($documentId);
            $pageCount = count($pageMappings);
            $structure = $metsService->getTableOfContents($documentId);
            $tableOfContents = count($structure[0]) > 0 ? true : false;
        }

        if (isset($structure[0]) && count($structure[0]) > 0) {
            $chapterArr = $this->flattenStructure(array_filter($structure[0]));
            $firstChapter = $chapterArr[0]['chapterId'];
            $lastChapter = $chapterArr[count($chapterArr) - 1]['chapterId'];

            if (!isset($activeChapterId)) {
                if ($page === 1) {
                    $activeChapterId = $firstChapter;
                } else {
                    $activeChapterId = $this->getChapterId($chapterArr, $page);
                }
            }

            $activeChapterkey = array_search($activeChapterId, array_column($chapterArr, 'chapterId'));
            $chapterArrLastKey = array_keys($chapterArr);
            $chapterArrLastKey = end($chapterArrLastKey);

            $activeChapterFirstPage = abs(explode('_', $chapterArr[$activeChapterkey]['chapterFirstPage'])[1]);
            $activeChapterLastPage = abs(explode('_', $chapterArr[$activeChapterkey]['chapterLastPage'])[1]);

            if ($activeChapterkey > 0) {
                $previousChapterId = $chapterArr[$activeChapterkey - 1]['chapterId'];
                $previousChapterFirstPage = abs(explode('_', $chapterArr[$activeChapterkey - 1]['chapterFirstPage'])[1]);
                $isThereAPreviousChapter = true;
            }

            if ($activeChapterkey < $chapterArrLastKey) {
                $nextChapterId = $chapterArr[$activeChapterkey + 1]['chapterId'];
                $nextChapterFirstPage = abs(explode('_', $chapterArr[$activeChapterkey + 1]['chapterFirstPage'])[1]);
                $isThereANextChapter = true;
            }

            if ($page !== 1 && !empty($activeChapterFirstPage) && $page <= $activeChapterFirstPage && isset($previousChapterId)) {
                $previousPageChapterId = $previousChapterId;
            } else {
                $previousPageChapterId = $this->getChapterId($chapterArr, $page - 1);
            }

            if (isset($activeChapterLastPage) && $page >= $activeChapterLastPage && isset($nextChapterId)) {
                $nextPageChapterId = $nextChapterId;
            } else {
                $nextPageChapterId = $this->getChapterId($chapterArr, $page + 1);
            }
        }

        if (isset($pageMappings) && $pageMappings !== []) {
            $documentFirstPage = array_keys($pageMappings)[0];
            $documentLastPage = array_keys($pageMappings)[count($pageMappings) - 1];
        }

        $isValidPage = ($page >= 1 and $page <= $pageCount) ? true : false;

        $documentStructure->setPage($page);
        $documentStructure->setPageCount(isset($pageCount) ? $pageCount : null);
        $documentStructure->setIsValidPage($isValidPage);
        $documentStructure->setTableOfContents(isset($tableOfContents) ? $tableOfContents : null);
        $documentStructure->setIdentifier(isset($identifier) ? $identifier : null);
        $documentStructure->setFirstChapter(isset($firstChapter) ? $firstChapter : null);
        $documentStructure->setLastChapter(isset($lastChapter) ? $lastChapter : null);
        $documentStructure->setDocumentFirstPage(isset($documentFirstPage) ? $documentFirstPage : null);
        $documentStructure->setDocumentLastPage(isset($documentLastPage) ? $documentLastPage : null);
        $documentStructure->setIsThereAPreviousChapter(isset($isThereAPreviousChapter) ? $isThereAPreviousChapter : false);
        $documentStructure->setIsThereANextChapter(isset($isThereANextChapter) ? $isThereANextChapter : false);
        $documentStructure->setPreviousChapterId(isset($previousChapterId) ? $previousChapterId : null);
        $documentStructure->setPreviousChapterFirstPage(isset($previousChapterFirstPage) ? $previousChapterFirstPage : null);
        $documentStructure->setNextChapterId(isset($nextChapterId) ? $nextChapterId : null);
        $documentStructure->setNextChapterFirstPage(isset($nextChapterFirstPage) ? $nextChapterFirstPage : null);
        $documentStructure->setNextPageChapterId(isset($nextPageChapterId) ? $nextPageChapterId : null);
        $documentStructure->setPreviousPageChapterId(isset($previousPageChapterId) ? $previousPageChapterId : null);

        return $this->render('SubugoeFindBundle:Default:detail.html.twig', [
                        'document' => $document[0]->getFields(),
                        'pageMappings' => isset($pageMappings) ? $pageMappings : null,
                        'documentStructure' => $documentStructure,
                ]);
    }

    /**
     * @Route("/content/{action}", name="_content", methods={"GET"})
     */
    public function contentAction($action)
    {
        $file = $this->get('kernel')->getRootDir().'/Resources/content/'.$action.'.md';

        if (file_exists($file)) {
            $content = file_get_contents($file);
        } else {
            return $this->redirect($this->generateUrl('_homepage'));
        }

        return $this->render('partials/site/content.html.twig', ['content' => $content]);
    }

    /**
     * @Route("/volumes/id/{id}", name="_volumes", methods={"GET"})
     *
     * @param Request $request A request instance
     * @param string  $id      The document id
     *
     * @return Response
     */
    public function volumesAction(Request $request, $id)
    {
        $sort = $request->get('sort');
        if (isset($sort) && empty($sort)) {
            throw new InvalidParameterException(sprintf('Sort argument must be provided.'));
        }

        $client = $this->get('solarium.client');

        $search = $this->getSearchEntity($request);
        $select = $this->getQuerySelect($request);
        $this->addQuerySort($select);
        $activeFacets = $request->get('filter');
        $this->addQueryFilters($select, $activeFacets);
        $facets = $client->select($select)->getFacetSet()->getFacets();
        $facetCounter = $this->get('subugoe_find.query_service')->getFacetCounter($activeFacets);

        $selectParentDocument = $client->createSelect()
                 ->setQuery(sprintf('id:%s', $id));
        $parentDocument = $client
                 ->select($selectParentDocument)
                 ->getDocuments()[0]
                 ->getFields();

        $selectChildrenDocuments = $client->createSelect()->setRows((int) 500);

        if (isset($sort) && !empty($sort)) {
            $selectChildrenDocuments->addSort(trim($sort), 'asc');
        } else {
            $selectChildrenDocuments->addSort('currentnosort', 'asc');
        }

        $selectChildrenDocuments->setQuery(sprintf('idparentdoc:%s AND docstrct:volume', $id));
        $pagination = $this->getPagination($request, $selectChildrenDocuments);

        return $this->render('SubugoeFindBundle:Default:index.html.twig', [
                    'facets' => $facets,
                    'facetCounter' => $facetCounter,
                    'queryParams' => $request->get('filter') ?: [],
                    'search' => $search,
                    'pagination' => $pagination,
                    'parentDocument' => $parentDocument,
                ]);
    }

    /*
     * This flattens the document structure for navigation
     *
     * @param array $structure The document structure
     *
     * @return array $chapterArr The flattend chapter structure
     */
    protected function flattenStructure($structure)
    {
        $chapterArr = [];
        foreach ($structure as $chapter) {
            if (count($chapter->getChildren()) > 0) {
                $chapterArr = array_merge($chapterArr, $this->flattenStructure($chapter->getChildren()));
            } else {
                $chapterArr[] = ['chapterId' => $chapter->getId(),
                        'chapterFirstPage' => $chapter->getPhysicalPages()[0],
                        'chapterLastPage' => $chapter->getPhysicalPages()[count($chapter->getPhysicalPages()) - 1],
                ];
            }
        }

        return $chapterArr;
    }

    /*
     * This returns the chapter id for a given page
     *
     * @param array $chapterArr The chapter array
     * @param integer $page The page number
     *
     * @return string $chapterId The chapter id
     */
    protected function getChapterId($chapterArr, $page)
    {
        foreach ($chapterArr as $chapter) {
            $chapterFirstPage = ltrim(explode('_', $chapter['chapterFirstPage'])[1], 0);
            $chapterLastPage = ltrim(explode('_', $chapter['chapterLastPage'])[1], 0);

            if (in_array($page, range($chapterFirstPage, $chapterLastPage)) && $page !== $chapterLastPage) {
                $chapterId = $chapter['chapterId'];

                return $chapterId;
            }
        }

        return false;
    }

    /*
     * @param Request $request A request instance
     *
     * @return Search $search A Search entity instance
     */
    protected function getSearchEntity(Request $request)
    {
        $search = new Search();

        $scope = $request->get('scope');

        if (!empty($scope)) {
            $search->setQuery($scope.':'.$request->get('q'));
        } else {
            $search->setQuery($request->get('q'));
        }

        $search
            ->setRows((int) $this->getParameter('results_per_page'))
            ->setCurrentPage((int) $request->get('page') ?: 1);

        return $search;
    }

    /*
     * @param Query $select A Query instance
     */
    protected function addQuerySort(Query $select, $sort = '', $order = '')
    {
        $queryService = $this->get('subugoe_find.query_service');

        if (!empty($sort) && !empty($order)) {
            $sort = $queryService->getSorting($sort.' '.$order);
        } else {
            $sort = $queryService->getSorting();
        }

        if (is_array($sort) && $sort != []) {
            $select->addSort($sort[0], $sort[1]);
        }
    }
}
