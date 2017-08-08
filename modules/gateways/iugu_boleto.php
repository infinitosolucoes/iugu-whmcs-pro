<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}
use Illuminate\Database\Capsule\Manager as Capsule;
require_once("iugu/src/Unirest.php");
/**
 * Define module related meta data.
 *
 * Values returned here are used to determine module related capabilities and
 * settings.
 *
 * @see http://docs.whmcs.com/Gateway_Module_Meta_Data_Parameters
 *
 * @return array
 */
function iugu_boleto_MetaData()
{
    return array(
        'DisplayName' => 'Iugu WHMCS Pro - Boleto',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCredtCardInput' => true,
        'TokenisedStorage' => true,
    );
}

/**
 * Define gateway configuration options.
 *
 * The fields you define here determine the configuration options that are
 * presented to administrator users when activating and configuring your
 * payment gateway module for use.
 *
 * Supported field types include:
 * * text
 * * password
 * * yesno
 * * dropdown
 * * radio
 * * textarea
 *
 * Examples of each field type and their possible configuration parameters are
 * provided in the sample function below.
 *
 * @return array
 */
function iugu_boleto_config(){
    return array(
        // nome amigável do módulo
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Iugu WHMCS v1.5 - Boleto',
        ),
        // token da API da Iugu
        'api_token' => array(
            'FriendlyName' => 'Token',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Acesse sua conta Iugu para gerar seu token.',
        ),
        // dias adicionais para vencimento do boleto
        'dias' => array(
            'FriendlyName' => 'Dias Adicionais',
						'Type' => 'dropdown',
            'Options' => array(
                '1' => '1 dia',
                '2' => '2 dias',
                '3' => '3 dias',
                '4' => '4 dias',
                '5' => '5 dias',
            ),
            'Description' => 'Quantos dias serão acrescidos após o boleto estar vencido?',
        ),
        'cpf_cnpj_field' => array(
            'FriendlyName' => 'Campo CPF/CNPJ',
            'Type' => 'text',
            'Size' => '20',
            'Default' => '',
            'Description' => 'Insira o nome referente ao campo CPF/CNPJ',
        ),
        'ignore_due_email' => array(
            'FriendlyName' => 'Ignorar e-mails de cobrança da Iugu',
            'Type' => 'yesno',
            'Description' => 'Desabilitar o envio de e-mails da Iugu diretamente ao cliente.'
        ),
    );
}

// Busca na tabela modmod_iugu_invoices_iugu se já existe uma fatura criada na Iugu referente a invoice do WHMCS
function iugu_boleto_search_invoice( $invoice ) {
  //$iuguInvoiceId = Array();
  try{

    // $iuguInvoiceId = Capsule::table('mod_iugu')->where('invoice_id', $invoiceid)->value('iugu_id');
    // procura no banco
    $iuguInvoiceId = Capsule::table('mod_iugu_invoices')->where('invoice_id', $invoice)->value('iugu_id');

    // loga a ação para debug
    logModuleCall("Iugu Boleto","Buscar Fatura",$invoice,json_decode($iuguInvoiceId, true));

    // retorna o ID da fatura
    return $iuguInvoiceId;

  }catch (\Exception $e){
    echo "Problemas em localizar a fatura no banco local. {$e->getMessage()}";
  }
}


function iugu_boleto_link( $params ){

// System Parameters
	$apiToken = $params['api_token'];
  $systemUrl = $params['systemurl'];
  $returnUrl = $params['returnurl'];
  $expired_url = $returnUrl;
	$notification_url = $systemUrl . '/modules/gateways/callback/iugu_boleto.php';
  $langPayNow = "Imprimir Boleto";

// Client Parameters
  $userid = $params['clientdetails']['userid'];
  $fullname = $params['clientdetails']['fullname'];
  $email = $params['clientdetails']['email'];
  $address1 = $params['clientdetails']['address1'];
  $address2 = $params['clientdetails']['address2'];
  $city = $params['clientdetails']['city'];
  $state = $params['clientdetails']['state'];
  $postcode = $params['clientdetails']['postcode'];
  $country = $params['clientdetails']['country'];
  $campoDoc = $params['cpf_cnpj_field'];
  $cpf_cnpj = $params['clientdetails'][$campoDoc];

	// Invoice Parameters
	$invoiceid = $params['invoiceid'];
	$description = $params["description"];

  // solicitação a API interna do WHMCS para busca de detalhes da fatura, principalmente sua data de vencimento
  $command = "GetInvoice";
  $postData = array(
    'invoiceid' => $invoiceid,
  );
  $results = localAPI($command,$postData);
  $dueDate = date('d/m/Y', strtotime($results['duedate']));
  $today = date(d/m/Y);
  if($today > $dueDate) {
    $dueDate = $today;
  }


	/** @var stdClass $itens */
	$itens = Array();
	try {
    $selectInvoiceItens = Capsule::table('tblinvoiceitems')->select('amount', 'description')->where('invoiceid', $invoiceid)->get();
			}catch (\Exception $e) {
    		echo "Não foi possível gerar os itens da fatura. {$e->getMessage()}";
				}

  foreach ($selectInvoiceItens as $key => $value) {
    $valor = number_format($value->amount, 2, '', '');
    $item = Array();
    $item['description'] = $value->description;
    $item['quantity'] = "1";
    $item['price_cents'] = $valor;
    $itens[] = $item;
  }

  // basic auth
  Unirest\Request::auth($apiToken, '');


  // busca informações da fatura no banco local para comparação e verificação
  $iuguInvoiceId = iugu_boleto_search_invoice( $invoiceid );

  // se não retornar uma fatura com o ID procurado, presume-se que é nova. Então cadastra.
  if( !$iuguInvoiceId ){

    $headers = array('Accept' => 'application/json');
    $data = array(
          "email" => $email,
      		"due_date" => $dueDate,
      		"return_url" => $returnUrl,
      		"expired_url" => $expired_url,
      		"notification_url" => $notification_url,
          "payable_with" => 'bank_slip',
      		"items" => $itens,
      		"ignore_due_email" => false,
      		"custom_variables" => array(
      			array(
      				"name" => "invoice_id",
      				"value" => $invoiceid
      			)
      		),
      		"payer" => array(
      			"cpf_cnpj" => $cpf_cnpj,
      			"name" => $fullname,
      			"email" => $email,
      			"address" => array(
      				"street" => $address1,
      				"number" => "000",
      				"city" => $city,
      				"state" => $state,
      				"country" => $country,
      				"zip_code" => $postcode
      			)
      		));

    $body = Unirest\Request\Body::json($data);

    $createInvoice = Unirest\Request::post('https://api.iugu.com/v1/invoices', $headers, $body);

    logModuleCall("Iugu Boleto","Gerar Fatura", $invoiceid, json_decode($createInvoice, true));
    // insere na tabela mod_iugu_invoices os dados de retorno referente a criação da fatura Iugu
    Capsule::table('mod_iugu_invoices')->insert(
                                                          [
                                                            'invoice_id' => $invoiceid,
                                                            'iugu_id' => $createInvoice->id,
                                                            'secure_id' => $createInvoice->secure_id
                                                          ]
                                                        );

  $htmlOutput = '<a class="btn btn-success btn-lg" target="_blank" role="button" href="'.$createInvoice->secure_url.'?bs=true">'.$langPayNow.'</a>
                <p>Linha Digitável: <br><small>'.$createInvoice->bank_slip->digitable_line.'</small></p>
                <p><img class="img-responsive" src="'.$createInvoice->bank_slip->barcode.'" ></p>
                ';
  return $htmlOutput;
}else {
    // caso a fatura já exista nos registros do banco local, busco as informações na Iugu desta fatura
    Iugu::setApiKey($apiToken);
    $fetchInvoice = Iugu_Invoice::fetch($iuguInvoiceId);
    //print_r($fetchInvoice);
    logModuleCall("Iugu Boleto","Buscar Fatura Iugu",$invoiceid,json_decode($fetchInvoice, true));

    $htmlOutput = '<a class="btn btn-success btn-lg" target="_blank" role="button" href="'.$fetchInvoice->secure_url.'?bs=true">'.$langPayNow.'</a>
                  <p>Linha Digitável: <br><small>'.$fetchInvoice->bank_slip->digitable_line.'</small></p>
                  <p><img class="img-responsive" src="'.$fetchInvoice->bank_slip->barcode.'" ></p>
                  ';

    return $htmlOutput;

  }

} //function


?>
