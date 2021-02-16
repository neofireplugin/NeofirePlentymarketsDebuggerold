<?php declare(strict_types=1);

namespace NeofirePlentymarketsDebugger\Administration\Controller;

use Doctrine\DBAL\Connection;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use Shopware\Core\System\SystemConfig\SystemConfigService;

use NeofirePlentymarketsConnector\Administration\Service\PlentymarketsBase;
use NeofirePlentymarketsConnector\Administration\Service\PlentymarketsConnect;
use NeofirePlentymarketsConnector\Administration\Service\PlentymarketsOrders;
use NeofirePlentymarketsConnector\Administration\Service\PlentymarketsStock;
use NeofirePlentymarketsConnector\Administration\Service\PlentymarketsCategories;
use NeofirePlentymarketsConnector\Administration\Service\PlentymarketsProduct;
use NeofirePlentymarketsConnector\Administration\Service\PlentymarketsData;
use NeofirePlentymarketsConnector\Administration\Service\PlentymarketsDatacheck;
use NeofirePlentymarketsConnector\Administration\Service\PlentymarketsRestarts;
use NeofirePlentymarketsConnector\Administration\Service\PlentymarketsDeliveryTime;
use NeofirePlentymarketsConnector\Administration\Service\PlentymarketsManufacturers;
use NeofirePlentymarketsConnector\Administration\Service\PlentymarketsUnits;
use NeofirePlentymarketsConnector\Administration\Service\PlentymarketsAttributes;
use NeofirePlentymarketsConnector\Administration\Service\PlentymarketsVariationAttributes;
use NeofirePlentymarketsConnector\Administration\Service\PlentymarketsFields;

class PlentymarketsController extends AbstractController
{
    private $systemConfigService;
    private $plentymarketsBase;
    private $finder;
    private $plentymarketsOrders;
    private $plentymarketsStock;
    private $plentymarketsCategories;
    private $plentymarketsProduct;
    private $plentymarketsData;
    private $plentymarketsDatacheck;
    private $plentymarketsRestarts;
    private $plentymarketsDeliveryTime;
    private $plentymarketsManufacturers;
    private $plentymarketsUnits;
    private $plentymarketsAttributes;
    private $plentymarketsVariationAttributes;
    private $plentymarketsFields;
    private $dbal;
    private $context;
    private $request;

    private $iframeSuccessValue = "SUCCESS";

    public function __construct(
        SystemConfigService $systemConfigService,
        PlentymarketsBase $plentymarketsBase,
        TemplateFinder $finder,
        PlentymarketsOrders $plentymarketsOrders,
        PlentymarketsStock $plentymarketsStock,
        PlentymarketsCategories $plentymarketsCategories,
        PlentymarketsProduct $plentymarketsProduct,
        PlentymarketsData $plentymarketsData,
        PlentymarketsDatacheck $plentymarketsDatacheck,
        PlentymarketsRestarts $plentymarketsRestarts,
        PlentymarketsDeliveryTime $plentymarketsDeliveryTime,
        PlentymarketsManufacturers $plentymarketsManufacturers,
        PlentymarketsUnits $plentymarketsUnits,
        PlentymarketsAttributes $plentymarketsAttributes,
        PlentymarketsVariationAttributes $plentymarketsVariationAttributes,
        PlentymarketsFields $plentymarketsFields,
        Connection $dbal
    )
    {
        $this->systemConfigService = $systemConfigService;
        $this->plentymarketsBase = $plentymarketsBase;
        $this->finder = $finder;
        $this->plentymarketsOrders = $plentymarketsOrders;
        $this->plentymarketsStock = $plentymarketsStock;
        $this->plentymarketsCategories = $plentymarketsCategories;
        $this->plentymarketsProduct = $plentymarketsProduct;
        $this->plentymarketsData = $plentymarketsData;
        $this->plentymarketsDatacheck = $plentymarketsDatacheck;
        $this->plentymarketsRestarts = $plentymarketsRestarts;
        $this->plentymarketsDeliveryTime = $plentymarketsDeliveryTime;
        $this->plentymarketsManufacturers = $plentymarketsManufacturers;
        $this->plentymarketsUnits = $plentymarketsUnits;
        $this->plentymarketsAttributes = $plentymarketsAttributes;
        $this->plentymarketsVariationAttributes = $plentymarketsVariationAttributes;
        $this->plentymarketsFields = $plentymarketsFields;
        $this->dbal = $dbal;
    }



    //Hier wird die Iframe URL sowie die Session übertragen
    /**
     * @RouteScope(scopes={"administration"})
     * @Route("/api/v{version}/admin/plentymarkets/getaccess", name="api.admin.plentymarkets.getaccess", methods={"GET"})
     */
    public function getaccess(Request $request):JsonResponse
    {
        session_cache_limiter('');
        session_name('neofire_sid');
        session_start();

        $_SESSION['NeofireiFrameAuth'] = $this->iframeSuccessValue;

        $response = new JsonResponse([
            'apidata' => '/admin/plentymarkets?start=1',
        ]);

        return $response;
    }


    // Hier wird gerprüft, ob das Iframe im Admin angezeigt werden darf
    /**
     * @RouteScope(scopes={"administration"})
     * @Route("/admin/plentymarkets", defaults={"auth_required"=false}, name="api.admin.plentymarkets", methods={"GET", "POST"})
     */
    public function iframe(Request $request, Context $context)
    {
        session_cache_limiter('');
        session_name('neofire_sid');
        session_start();

        $config =  $this->systemConfigService->get('NeofirePlentymarketsDebugger.config');

        if($request->get('key') == $config['securekey']){
            $_SESSION['NeofireiFrameAuth'] = $this->iframeSuccessValue;
        }

        if($_SESSION['NeofireiFrameAuth'] == $this->iframeSuccessValue) {

            $this->import($request, $context);
            exit();

        }else{
            
            $error_template = $this->finder->find('@Administration/administration/page/content/neofire_plentymarkets_error.html.twig');
            $response = $this->render($error_template, ['output' => 'Keine Admin Authentifizierung!']);
            $response->headers->set('X-Frame-Options', 'SameOrigin');
            $response->send();
            exit();
        }
    }
    

    // Hier werden alle Aufgaben erledigt.
    public function import(Request $request, Context $context)
    {

        $startzeit = time();

        $output = '';
        $info = '';
        
        
        //pentymarkets token holen
        $token = $this->plentymarketsBase->getCurrentAccessToken();

        // falls kein plentymarkets token exisitiert
        if(empty($token)){

            $error_template = $this->finder->find('@Administration/administration/page/content/neofire_plentymarkets_error.html.twig');
            $response = $this->render($error_template, ['output' => 'plentymarkets: keine Api Rechte!']);
            $response->headers->set('X-Frame-Options', 'SameOrigin');
            $response->send();
            exit();

        }


        // Übersichtsseite mit Buttons
        if(!empty($request->get('start'))){

            $overview_template = $this->finder->find('@Administration/administration/page/content/neofire_plentymarkets_overview.html.twig');
            $response = $this->render($overview_template, ['output' => $output]);
            $response->headers->set('X-Frame-Options', 'SameOrigin');
            $response->send();
            exit();
        }


        //Neustart vom Datenabgleich
        $output = $this->plentymarketsRestarts->import($request->get('restart'));

        if(!empty($output)){

            $overview_template = $this->finder->find('@Administration/administration/page/content/neofire_plentymarkets_overview.html.twig');
            $response = $this->render($overview_template, ['output' => $output]);
            $response->headers->set('X-Frame-Options', 'SameOrigin');
            $response->send();
            exit();
        }


        //Live Abfrage mit Details
        $output .= $this->plentymarketsDatacheck->import($startzeit);
        if(!empty($output)){

            $live_template = $this->finder->find('@Administration/administration/page/content/neofire_liveoutput.html.twig');
            $response = $this->render($live_template, ['output' => $output,'time' => round((microtime(true) - $startzeit),2)]);
            $response->headers->set('X-Frame-Options', 'SameOrigin');
            $response->send();
            exit();

        }

        //Abfrage, ob ein Neustart angefordert wurde
        $builder = $this->dbal->createQueryBuilder();
        $builder->select('*')->from('NeofirePlentymarketsConnector_times')->where('success = :success');
        $builder->setParameter(':success', '0');
        $stmt = $builder->execute();
        $job = $stmt->fetch();
        $stmt->closeCursor();


        //Kategorien anlegen
        if($job['typ'] == 'categories'){
            $output .= $this->plentymarketsCategories->import('');

            $html_output = $this->finder->find('@Administration/administration/page/content/neofire_plentymarkets.html.twig');
            $response = $this->render($html_output, ['output' => $output,'info' => $info,'time' => round((microtime(true) - $startzeit),2)]);
            $response->headers->set('X-Frame-Options', 'SameOrigin');
            $response->send();
            exit();
        }


        //Lieferzeiten anlegen
        if($job['typ'] == 'deliverytimes') {
            $output .= $this->plentymarketsDeliveryTime->import('');

            $html_output = $this->finder->find('@Administration/administration/page/content/neofire_plentymarkets.html.twig');
            $response = $this->render($html_output, ['output' => $output, 'info' => $info,'time' => round((microtime(true) - $startzeit),2)]);
            $response->headers->set('X-Frame-Options', 'SameOrigin');
            $response->send();
            exit();
        }


        //Hersteller anlegen
        if($job['typ'] == 'manufacturers') {
            $output .= $this->plentymarketsManufacturers->import('');

            $html_output = $this->finder->find('@Administration/administration/page/content/neofire_plentymarkets.html.twig');
            $response = $this->render($html_output, ['output' => $output, 'info' => $info,'time' => round((microtime(true) - $startzeit),2)]);
            $response->headers->set('X-Frame-Options', 'SameOrigin');
            $response->send();
            exit();
        }

        //Units anlegen
        if($job['typ'] == 'units') {
            $output .= $this->plentymarketsUnits->import('');

            $html_output = $this->finder->find('@Administration/administration/page/content/neofire_plentymarkets.html.twig');
            $response = $this->render($html_output, ['output' => $output, 'info' => $info,'time' => round((microtime(true) - $startzeit),2)]);
            $response->headers->set('X-Frame-Options', 'SameOrigin');
            $response->send();
            exit();
        }


        //Merkmale anlegen
        if($job['typ'] == 'poperties') {
            $output .= $this->plentymarketsAttributes->import('');

            $html_output = $this->finder->find('@Administration/administration/page/content/neofire_plentymarkets.html.twig');
            $response = $this->render($html_output, ['output' => $output,'info' => $info,'time' => round((microtime(true) - $startzeit),2)]);
            $response->headers->set('X-Frame-Options', 'SameOrigin');
            $response->send();
            exit();
        }


        //Eigenschaften anlegen
        if($job['typ'] == 'variationpoperties') {
            $output .= $this->plentymarketsVariationAttributes->import('');

            $html_output = $this->finder->find('@Administration/administration/page/content/neofire_plentymarkets.html.twig');
            $response = $this->render($html_output, ['output' => $output,'info' => $info,'time' => round((microtime(true) - $startzeit),2)]);
            $response->headers->set('X-Frame-Options', 'SameOrigin');
            $response->send();
            exit();
        }


        //Felder anlegen
        if($job['typ'] == 'fields') {
            $output .= $this->plentymarketsFields->import('');

            $html_output = $this->finder->find('@Administration/administration/page/content/neofire_plentymarkets.html.twig');
            $response = $this->render($html_output, ['output' => $output,'info' => $info,'time' => round((microtime(true) - $startzeit),2)]);
            $response->headers->set('X-Frame-Options', 'SameOrigin');
            $response->send();
            exit();
        }


        //Bestellungen werden zu plentymarkets übertragen
        $output .= $this->plentymarketsOrders->import('');

        //Artikel werden bei plentymarkets abgefragt
        $info .= $this->plentymarketsData->getData();

        //Hier werden die Produkte aktualisiert
        $output .= $this->plentymarketsData->import($startzeit,'items');

        if($output == ''){
            $output .= '<meta http-equiv="refresh" content="5"; URL="plentymarkets">';
            $output .= '<div class="checkmark"></div>';
        }

        $html_output = $this->finder->find('@Administration/administration/page/content/neofire_plentymarkets.html.twig');
        $response = $this->render($html_output, ['output' => $output,'info' => $info,'time' => round((microtime(true) - $startzeit),2)]);
        $response->headers->set('X-Frame-Options', 'SameOrigin');
        $response->send();
        exit();
    }
}