<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Solarium\QueryType\Select\Result\DocumentInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;

class DocumentController extends Controller
{
    /**
     * @Route("/anchor/{id}", name="_anchor", methods={"GET"})
     */
    public function anchorDocumentAction($id)
    {
        $client = $this->get('solarium.client');

        $selectParentDocument = $client->createSelect()
                ->setQuery(sprintf('id:%s', $id));
        $parentDocument = $client
                ->select($selectParentDocument)
                ->getDocuments()[0]
                ->getFields();

        $selectChildrenDocuments = $client->createSelect()->setRows(500)
                ->addSort('currentno', 'ASC')
                ->setQuery(sprintf('idparentdoc:%s AND docstrct:volume', $id));
        $childrenDocuments = $client->select($selectChildrenDocuments)->getDocuments();

        return $this->render('partials/app/anchor.html.twig', [
            'parentDocument' => $parentDocument,
            'childrenDocuments' => $childrenDocuments,
        ]);
    }

    /**
     * @param DocumentInterface $document
     *
     * @return bool
     */
    protected function onlyOneParent($document)
    {
        if (count($document->getFields()['idparentdoc']) === 1) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @Route("/id/{id}/toc/", name="_toc", methods={"GET"})
     */
    public function tocAction($id)
    {
        if (strchr($id, '|')) {
            $id = explode('|', $id)[0];
        }

        $metsService = $this->get('mets_service');

        $structure = array_filter($metsService->getTableOfContents($id)[0]);

        return $this->render('toc.html.twig', [
            'structure' => $structure,
            'pageMappings' => $metsService->getScannedPagesMapping($id),
            'id' => $id,
        ]);
    }

    /**
     * @param string $url
     *
     * @return Response
     */
    public function fullTextAction($url)
    {
        $teiProcessor = $this->get('tei_processor');
        $teiFile = $this->get('cache.app')->getItem('tei.'.sha1($url));

        if (!$teiFile->isHit()) {
            $client = $this->get('guzzle.client.fulltext');
            $file = $client
            ->get($url)
            ->getBody()->__toString();

            $teiFile->set($file);
            $this->get('cache.app')->save($teiFile);
        }

        $text = $teiProcessor->process($teiFile->get());

        return new Response($text);
    }

    /**
     * @Route("/{id}/mets.xml", name="_mets", methods={"GET"})
     *
     * @param string $id
     *
     * @return Response
     */
    public function metsAction($id)
    {
        $client = $this->get('guzzle.client.mets');
        $file = $client
            ->get($id.'.xml')
            ->getBody();

        $response = new Response(
            $file,
            Response::HTTP_OK,
            [
                'content-type' => 'application/xml',
            ]
        );

        return $response;
    }

    /**
     * @Route("/just-scanned/", name="just-scanned", methods={"GET"})
     */
    public function justScannedAction()
    {
        $client = $this->get('solarium.client');
        $select = $client->createSelect();
        $select->setRows(1);
        $select->addSort('dateindexed', 'desc');

        return $this->render('partials/app/just-scanned.html.twig', [
            'document' => $client->select($select)->getDocuments()[0],
        ]);
    }
}
