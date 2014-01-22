<?php

require '../vendor/autoload.php';
require '../config.php';
use RedBean_Facade as R;

class ResourceNotFoundException extends Exception {}

$dsn      = 'mysql:host='.$config['db']['host'].';dbname='.$config['db']['database'];
$username = $config['db']['user'];
$password = $config['db']['password'];

R::setup($dsn,$username,$password);
R::freeze(true);

$log_level = \Slim\Log::WARN;
$log_enable = false;
if ( isset($config['log']) ){
	$handlers = array();
	if ( $config['enviroment'] == 'production' && isset($config['log']['hipchat']) ) {
		$hipchat = $config['log']['hipchat'];
		$handlers[] = new \Monolog\Handler\HipChatHandler($hipchat['token'], $hipchat['room'], $hipchat['name'], $hipchat['notify'], \Monolog\Logger::INFO, $hipchat['bubble'], $hipchat['useSSL']);
	}
	$handlers[] = new \Monolog\Handler\StreamHandler($config['log']['filename']);	
	$logger = new \Flynsarmy\SlimMonolog\Log\MonologWriter(array(
	    'handlers' => $handlers
	));
	switch ($config['log']['level']) {
		case "EMERGENCY" 	:
			$log_level = \Slim\Log::EMERGENCY;
			break;
		case "ALERT" 		:
			$log_level = \Slim\Log::ALERT;
			break;
		case "CRITICAL"		:
			$log_level = \Slim\Log::CRITICAL;
			break;
		case "ERROR"		:
			$log_level = \Slim\Log::ERROR;
			break;
		case "WARN"			:
			$log_level = \Slim\Log::WARN;
			break;
		case "NOTICE"		:
			$log_level = \Slim\Log::NOTICE;
			break;
		case "INFO"			:
			$log_level = \Slim\Log::INFO;
			break;
		case "DEBUG"		:
			$log_level = \Slim\Log::DEBUG;
			break;
		default:	
			$log_level = \Slim\Log::WARN;
			break;
	}
	$log_enable = true;
}

$app = new \Slim\Slim(array(
	'mode' => $config['enviroment']
));

$app->config(array(
    'log.enabled' => $log_enable,
    'log.level' => $log_level,
    'log.writer' => $logger,
    'templates.path' => $config['template_dir']."/".$config['enviroment'],
    'title' => $config['title'],
    'import' => $config['import']
));

// Only invoked if mode is "production"
$app->configureMode('production', function () use ($app) {
    $app->config(array(
        'debug' => false
    ));
});

// Only invoked if mode is "development"
$app->configureMode('development', function () use ($app) {
    $app->config(array(
	    /*'oauth.cliendId' => 'r-index',
	    'oauth.secret' => 'testpass',
	    'oauth.url' => 'http://localhost:9000', */
        'debug' => true
    ));
});

// error reporting 
if ( DEBUG ) { ini_set('display_errors',1);error_reporting(E_ALL); }

// handle GET requests for /
$app->get('/', function () use ($app) {  

	//$log = $app->log;
	//$log->debug('called /');

	$title = $app->config('title');

	$app->render('index.html', array(
		'title' => $title,
		'footerText' => '©2014 Stefano Tamagnini. Design by ....'
	));

});

$app->get('/location', function () use ($app) {  
	
	$app->response->headers->set('Content-Type', 'application/json');

	$location = $app->request->params('location');

	$elenco_luoghi = R::getCol('select distinct nascita from asa where nascita like "'.$location.'%" order by nascita ASC');
	foreach($elenco_luoghi as &$value2)
	{
	    $value2 = ucwords(strtolower($value2));
	}
	unset($value2); # remove the alias for safety reasons.
	/*
	 Array
	    (
	        [0] => Abbiategrasso
	        [1] => ..
	        [2] => ..
	    )
	*/
	$t = new StdClass();
	$t->results = $elenco_luoghi;
	$app->response->setBody( json_encode(  $t ) );

});

$app->post('/fileupload', function () use ($app) {  

	$log = $app->log;

	$import = $app->config('import');
	$upload_dir = $import['upload_dir'];

	foreach ($_FILES["uploadedFile"]["error"] as $key => $error) {
		if ($error == UPLOAD_ERR_OK) {
			$tmp_name = $_FILES["uploadedFile"]["tmp_name"][$key];
			$type = $_FILES["uploadedFile"]["type"][$key];

			$name = $_FILES["uploadedFile"]["name"][$key];
			$ext = pathinfo($name, PATHINFO_EXTENSION);

			//$objDateTime = new DateTime('NOW');
			//$name = "upload-".$objDateTime->format(DateTime::W3C).".".$ext;
			$name = "upload-".microtime(true).".".$ext;
			
			move_uploaded_file($tmp_name, "$upload_dir/$name");

			$info = new SplFileInfo("$upload_dir/$name");
			
			$t = new StdClass();
			$t->filename = $info->getRealPath();
			
			$task = R::dispense('task');
			$task->arguments = json_encode($t);
			$task->created = R::isoDateTime();
			$task->updated = R::isoDateTime();
			$task->status = \Rescue\RequestStatus::QUEUE;
			$task->type = \Rescue\RequestType::IMPORT;
			$task_id = R::store($task);

			\Rescue\RescueLogger::taskLog($task_id,\Monolog\Logger::INFO,'Created task import from '.$_SERVER['REMOTE_ADDR']);

    	} else {
    		$log->error('Errore upload file : '.$key.' -> '.$error);
    	}
    }

	$app->response->headers->set('Content-Type', 'text/html');
	$app->response->setBody('Upload completato con successo');

});

/*
$app->get('/rescue' , function () use ($app) {  

	//check $app->halt(401,"REMOTE SITE STOP ME! -> $http_status");

	$app->response->headers->set('Content-Type', 'application/json');

});
*/

$app->post('/rescue/codicecensimento' , function () use ($app) {  

	$app->response->headers->set('Content-Type', 'application/json');

	/*$req = $app->request();
	$name_of_the_controller = $req->post('controller');
	$name_of_the_action =  $req->post('action');*/

	$body = $app->request->getBody();
	$app->log->debug('Richiesta ricerca codicecensimento ' . $body);

	$obj_request = json_decode($body);

	$nome = $obj_request->nome;
	$cognome = $obj_request->cognome;

	$datanascita = $obj_request->datanascita; //1981-09-18T23:22:51.000Z

	$datetime = new DateTime($datanascita);
	$datanascita = $datetime->format('Ymd'); //19810918
	$luogonascita = $obj_request->luogonascita;

	$find = R::findOne('asa',' nome = ? and cognome = ? ',array($nome,$cognome));
	if ( $find != null ) {
		$app->log->debug('nome e cognome validi procedo con l\'inserimento della richiesta');
		try {

			$t = new StdClass();
			$t->nome = $nome;
			$t->cognome = $cognome;
			$t->datanascita = $datanascita;
			$t->luogonascita = $luogonascita;

			$task = R::dispense('task');
			$task->arguments = json_encode($t);
			$task->created = R::isoDateTime();
			$task->updated = R::isoDateTime();
			$task->status = \Rescue\RequestStatus::QUEUE;
			$task->type = \Rescue\RequestType::SEARCH;
			$id = R::store($task);

			\Rescue\RescueLogger::taskLog($task_id,Logger::INFO,'Created task search from '.$_SERVER['REMOTE_ADDR']);

			$app->response->setBody( json_encode(array('id_richiesta' => $id)) );
		} catch(Exception $e) {
			$app->log->error($e->getMessage());
			$app->halt(412,"Dati invalidi"); //Precondition Failed
		}
	} else {
		$app->halt(412,"Nome e/o Cognomi invalidi"); //Precondition Failed
	}

});


// run
$app->run();

?>