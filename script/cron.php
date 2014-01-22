#!/usr/bin/php
<?php

require '../vendor/autoload.php';
require '../config.php';
use RedBean_Facade as R;

$dsn      = 'mysql:host='.$config['db']['host'].';dbname='.$config['db']['database'];
$username = $config['db']['user'];
$password = $config['db']['password'];

use Monolog\Logger;
use Monolog\Handler\TestHandler;

// create a log channel
$log = new Logger('cron');
$handler = new TestHandler(Logger::WARNING);
$log->pushHandler($handler);

if ( $config['enviroment'] == 'production' && isset($config['log']['hipchat']) ) {
	$hipchat = $config['log']['hipchat'];
	$hipchat_handler = new \Monolog\Handler\HipChatHandler($hipchat['token'], $hipchat['room'], $hipchat['name'], $hipchat['notify'], \Monolog\Logger::ERROR, $hipchat['bubble'], $hipchat['useSSL']);
	$log->pushHandler($hipchat_handler);
}

// add records to the log
//$log->addWarning('Foo');
//$log->addError('Bar');

R::setup($dsn,$username,$password);
R::freeze(true);

$task_list = R::find('task','status = ?', array(\Rescue\RequestStatus::QUEUE));
//$task_list = R::findAll('task');

foreach ($task_list as $task_id => $task) {
	
	$args = json_decode($task->arguments);

	$task->updated = R::isoDateTime();
	$task->status = \Rescue\RequestStatus::IN_PROGRESS;

	R::store($task);

	\Rescue\RescueLogger::taskLog($task_id,Logger::INFO,'Task preso in carico');
	
	if( \Rescue\RequestType::SEARCH == $task->type ) {

		$socio = R::getRow('select * from asa where nome = ? and cognome = ? and datan = ? and nascita = ?',
			array($args->nome,$args->cognome,$args->datanascita,$args->luogonascita));
		//R::getRow('select * from page where title like ? limit 1', array('%Jazz%'));

		$codice_socio = $socio['csocio'];
		$email = $socio['numero'];

		// Create the message
		$message = Swift_Message::newInstance()

		  // Give the message a subject
		  ->setSubject('Estrazione Codice Censimento')

		  // Set the From address with an associative array (ovverride from gmail if you use gmail account.)
		  ->setFrom(array('webmaster@emiroagesci.it' => 'Webmaster Emiro Agesci'))

		  // Set the To addresses with an associative array
		  ->setTo( array($email => $args->nome.' '.$args->cognome) )

		  // Give it a body
		  ->setBody('Codice Censimento : '.$codice_socio)

		  // And optionally an alternative body
		  ->addPart('<p>Codice Censimento : <strong>'.$codice_socio.'</strong></p>', 'text/html');

		  // Optionally add any attachments
		  //->attach(Swift_Attachment::fromPath('my-document.pdf'));

		// To use the ArrayLogger
		$logger = new Swift_Plugins_Loggers_ArrayLogger();

		$smtpConfig = $config['smtp'];

		$transport = Swift_SmtpTransport::newInstance($smtpConfig['host'], $smtpConfig['port'], $smtpConfig['security'])
		->setUsername($smtpConfig['username'])
  		->setPassword($smtpConfig['password']);

		// Create the Mailer using your created Transport
		$mailer = Swift_Mailer::newInstance($transport);
		$mailer->registerPlugin(new Swift_Plugins_LoggerPlugin($logger));

		// Pass a variable name to the send() method
		try {
			if (!$mailer->send($message, $failures))
			{
				//echo "Failures:";
				//print_r($failures);
				/*
				Failures:
				Array (
				  0 => receiver@bad-domain.org,
				  1 => other-receiver@bad-domain.org
				)
				*/
				$task->status = \Rescue\RequestStatus::FAILED;
				$task->result = "Fallito l'invio a ".json_decode($failures);
				R::store($task);
			} else {
				$task->status = \Rescue\RequestStatus::ELABORATED; 
				$task->result = "Inviato correttamente codice socio : $codice_socio a $email";
			}
		}
		catch(Swift_TransportException $e) {
			$message = $e->getMessage();
			$log->addError($message);
			$log->addError($e->getTraceAsString());
			$task->result = "Fallito l'invio, errore nel trasporto";
			$task->status = \Rescue\RequestStatus::FAILED; 
			R::store($task);
		}

		\Rescue\RescueLogger::taskLog($task_id,Logger::INFO,$logger->dump());

	} // end search 

	if( \Rescue\RequestType::IMPORT == $task->type ) {

		// /Users/yoghi/Documents/workspace/digital-rescue/test/resources/elenco.ods
		$args = json_decode($task->arguments);

		try {
			$importer = new \BitPrepared\Asa\Importer();
			$filename = $args->filename;
			$soci_trovati = $importer->carica();

			$soci = $soci_trovati[0];
			foreach ($soci as $cod_socio => $asa_socio) {
				\Rescue\RescueLogger::taskLog($task_id,Logger::INFO,'Import del codice socio '.$cod_socio);
				$find = R::findOne('asa',' csocio = ? ',array($cod_socio));
				if ( null == $find ) {
					$asa = R::dispense('asa');
					foreach ($asa_socio as $key => $value) {
						$asa->$key = $value;
					}
					$id = R::store($asa);
				} else {
					// CERCA LE 7 PICCOLE DIFFERENZE e FAI UPDATE E VERSIONING
					$log->addWarning('Utente '.$cod_socio.' gia esistente. SKIP for now');
				}
			}

			foreach ($soci_trovati[1] as $error ) {
				$log->addError($error);
			}

			$task->status = \Rescue\RequestStatus::ELABORATED; 
			$task->result = "Import del file $filename avvenuto correttamente";
		} 
		catch(Exception $e){
			$log->addError($message);
			$log->addError($e->getTraceAsString());
			$task->result = "Fallito l'invio, errore nel trasporto";
			$task->status = \Rescue\RequestStatus::FAILED;
			R::store($task);
		}


	} // end import

	R::store($task);

}

$log_records = $handler->getRecords();
/*
	{
	  "log_records": [
	    {
	      "message": "Failed to authenticate on SMTP server with username \"orsetto@gmail.com\" using 2 possible authenticators",
	      "context": [],
	      "level": 400,
	      "level_name": "ERROR",
	      "channel": "cron",
	      "datetime": {
	        "date": "2014-01-22 18:52:53",
	        "timezone_type": 3,
	        "timezone": "Europe/Rome"
	      },
	      "extra": [],
	      "formatted": "[2014-01-22 18:52:53] cron.ERROR: Failed to authenticate on SMTP server with username \"orsetto@gmail.com\" using 2 possible authenticators [] []\n"
	    }
	  ]
	}
*/
foreach ($log_records as $record) {
	\Rescue\RescueLogger::taskLog($task_id,$record['level_name'],$record['formatted']);
}


?>