<?php
/**
 * Librería MailBox
 * Yordanny Mejías Venegas.
 * Creado: 2018-08-14
 * Modificado: 2026-02-20
 * @link https://github.com/yordanny90/MailBox
 */
if(!function_exists('imap_open')){
    throw new ErrorException('Funciones IMAP inhabilitadas',1);
}
/**
 * Establece si se puede utilizar la librería multibyte para codificar/decodificar texto en UTF7 Modificado ("UTF7-IMAP")
 */
define('MAILBOX_UTF7_MULTIBYTE_FUNC', mb_encoding_aliases('UTF7-IMAP')!==false);
/**
 * Establece si se puede utilizar las funciones {@see imap_mutf7_to_utf8()} y {@see imap_utf8_to_mutf7()} para codificar/decodificar texto en UTF7 Modificado
 */
define('MAILBOX_UTF7_IMAPUTF8_FUNC', function_exists('imap_mutf7_to_utf8') && function_exists('imap_utf8_to_mutf7'));

/**
 * Class MailBox
 * Clase para la lectura de correos.
 */
class MailBox{
	const MAILBOX_MULTIBYTE_ENCONDING='ASCII,JIS,UTF-8,ISO-8859-1,EUC-JP,SJIS';
	const REGEXP_MAIL_ADDRESS='\b[^\"\(\),:;\<\>@\[\\]\s]+@[^\"\(\),:;\<\>@\[\\]\s]+\b';
	public $imap_stream=null;
	protected $mailbox='';
	protected $cfg_str='';
	protected $cfg_mailbox=array(
		'domain'=>'',
		'port'=>'',
		'service'=>'',
		'validateCert'=>'',
		'norsh'=>'',
		'ssl'=>'',
		'tls'=>'',
		'debug'=>'',
		'secure'=>'',
	);
	protected $params=array();
	public static $open_options=CL_EXPUNGE;
	public static $reopen_options=CL_EXPUNGE;
	public static $services=array('imap','imap2','imap2bis','imap4','imap4rev1','pop3','nntp');

	/**
	 * MailBox constructor.
	 * @param string|array $domain Dominio o la configuración completa de la conexión
	 * @param null $port
	 * @param null $service
	 * @see MailBox::$services
	 */
	public function __construct($domain=null,$port=null,$service=null,$ssl=null,$tls=null,$validateCert=null,$norsh=null,$disableAuth=null){
		if(is_array($domain) && count(func_get_args())==1){
			$cfg=$domain;
		}
		else{
			$cfg=compact('domain', 'port', 'service', 'ssl', 'tls', 'validateCert', 'norsh', 'disableAuth');
		}
		$this->load_config($cfg);
	}

	public function __destruct(){
		return $this->close();
	}

	public function __clone(){
		$n=new self('');
		$n->cfg_mailbox=$this->cfg_mailbox;
		$n->cfg_str=$this->cfg_str;
		$n->params=$this->params;
		return $n;
	}

	private function load_config(array &$cfg){
		if(!is_null($cfg['domain'])) $this->cfg_mailbox['domain']=$cfg['domain'];
		if(is_int($cfg['port']) && $cfg['port']>0){
			$this->cfg_mailbox['port']=':'.intval($cfg['port']);
		}
		if(!in_array($cfg['service'], self::$services)){
			$cfg['service']=null;
		}
		if(!is_null($cfg['service'])) $this->cfg_mailbox['service']='/'.$cfg['service'];
		if(!is_null($cfg['ssl'])) $this->cfg_mailbox['ssl']=($cfg['ssl']?'/ssl':'');
		if(!is_null($cfg['validateCert'])) $this->cfg_mailbox['validateCert']=($cfg['validateCert']?'/validate-cert':'/novalidate-cert');
		if(!is_null($cfg['tls'])) $this->cfg_mailbox['tls']=($cfg['tls']?'/tls':'/notls');
		if(!is_null($cfg['norsh'])) $this->cfg_mailbox['norsh']=($cfg['norsh']?'/norsh':'');
		if(!is_null($cfg['debug'])) $this->cfg_mailbox['debug']=($cfg['debug']?'/debug':'');
		if(!is_null($cfg['secure'])) $this->cfg_mailbox['secure']=($cfg['secure']?'/secure':'');
		if(!is_null($cfg['disableAuth'])){
			if($cfg['disableAuth']){
				$this->params['DISABLE_AUTHENTICATOR']='GSSAPI';
			}
			else{
				unset($this->params['DISABLE_AUTHENTICATOR']);
			}
		}
		$this->cfg_str=implode('', $this->cfg_mailbox);
	}

	/**
	 * Devuelve el mailbox actual
	 * @return string
	 */
	public function getMailBox(){
		return $this->mailbox;
	}

	protected function is_opened(){
		if(!$this->imap_stream) return false;
		$open=!function_exists('imap_is_open') || imap_is_open($this->imap_stream);
		if(!$open) $this->imap_stream=null;
		return $open;
	}

	protected function getcfg($extra=''){
		return '{'.$this->cfg_str.$extra.'}';
	}

	static function encode_utf7($str){
		if(MAILBOX_UTF7_MULTIBYTE_FUNC)
			return mb_convert_encoding($str,'UTF7-IMAP',self::MAILBOX_MULTIBYTE_ENCONDING);
		elseif(MAILBOX_UTF7_IMAPUTF8_FUNC)
			return imap_utf8_to_mutf7(mb_convert_encoding($str,'UTF-8',self::MAILBOX_MULTIBYTE_ENCONDING));
		else{
			return imap_utf7_encode(mb_convert_encoding($str, 'ISO-8859-1', self::MAILBOX_MULTIBYTE_ENCONDING));
		}
	}

	static function decode_utf7($str){
		if(MAILBOX_UTF7_MULTIBYTE_FUNC)
			return mb_convert_encoding($str,'UTF-8','UTF7-IMAP');
		elseif(MAILBOX_UTF7_IMAPUTF8_FUNC)
			return imap_mutf7_to_utf8($str);
		else
			return utf8_encode(imap_utf7_decode($str));
	}

	/**
	 * @return bool
	 * @see imap_ping()
	 */
	public function ping(){
		if(!$this->is_opened()) return false;
		$ping=imap_ping($this->imap_stream);
		if(!$ping) $this->imap_stream=null;
		return $ping;
	}

	public function open($username, $password, $mailbox='INBOX', $cfg=null, int $flags=0){
		if($this->ping()) return false;
		$username=self::encode_utf7($username);
		$password=self::encode_utf7($password);
		if(is_array($cfg)) $this->load_config($cfg);
		$flags=$flags|self::$open_options;
		$this->imap_stream=imap_open($this->getcfg().self::encode_utf7($mailbox), $username, $password,$flags, 3, $this->params);
		if($this->imap_stream){
			$this->mailbox=$mailbox;
		}
		return $this->ping();
	}

	public function reopen($mailbox, int $flags=0){
		$flags=$flags|self::$reopen_options;
		$success=imap_reopen($this->imap_stream, $this->getcfg().self::encode_utf7($mailbox), $flags, 3);
		if($success){
			$this->mailbox=$mailbox;
		}
		return $success;
	}

	/**
	 * @return bool
	 * @see imap_close()
	 */
	public function close(){
        if($this->ping()) imap_close($this->imap_stream);
        $this->mailbox='';
        $this->imap_stream=null;
		return true;
	}

	public function expunge(){
		if(!$this->is_opened()) return false;
		$res=imap_expunge($this->imap_stream);
		return $res;
	}

	public static function errors(){
		$res=imap_errors();
		return $res;
	}

	public static function last_error(){
		$res=imap_last_error();
		return $res;
	}

	/**
	 * @param null $mailbox
	 * @return bool|object
	 * @see imap_status()
	 */
	public function getStatus($mailbox=null){
		if(!$this->is_opened()) return false;
		if(!is_string($mailbox)) $mailbox=$this->mailbox;
		$res=imap_status($this->imap_stream,$this->getcfg().self::encode_utf7($mailbox),SA_ALL);
		return $res;
	}

	/**
	 * @param string $pattern
	 * @return array|bool
	 * @see imap_list()
	 */
	public function getMailBoxes($pattern='*'){
		if(!$this->is_opened()) return false;
		$ref=$this->getcfg();
		$res=imap_list($this->imap_stream, $ref, $pattern);
		if(is_array($res)){
			foreach($res AS &$r){
				$r=self::decode_utf7(str_replace($ref, '', $r));
			}
		}
		return $res;
	}

	/**
	 * @param string $pattern
	 * @return array|bool
	 * @see imap_lsub()
	 */
	public function getMailBoxes_subscribed($pattern='*'){
		if(!$this->is_opened()) return false;
		$ref=$this->getcfg();
		$res=imap_lsub($this->imap_stream, $ref, $pattern);
		if(is_array($res)){
			foreach($res AS &$r){
				$r=self::decode_utf7(str_replace($ref, '', $r));
			}
		}
		return $res;
	}

	/**
	 * Crea una nueva carpeta en el buzón
	 * @param string $mailbox Nombre de la carpeta.<br>
	 * No se recomienda el uso de caracteres especiales en los nombres de la carpetas.<br>
	 * Para mayor compatibilidad, utilize solo valores alfanuméricos sin acentos y espacios
	 * @return bool
	 * @see imap_createmailbox()
	 */
	public function createMailBox($mailbox){
		if(!$this->is_opened()) return false;
		$res=imap_createmailbox($this->imap_stream,$this->getcfg().self::encode_utf7($mailbox));
		return $res;
	}

	/**
	 * Elimina una cerpeta del buzón.
	 * @param string $mailbox Nombre de la carpeta.
	 * @return bool
	 */
	public function deleteMailBox($mailbox){
		if(!$this->is_opened()) return false;
		$res=imap_deletemailbox($this->imap_stream,$this->getcfg().self::encode_utf7($mailbox));
		return $res;
	}

	public function renameMailBox($old_mailbox, $new_mailbox){
		if(!$this->is_opened()) return false;
		$ref=$this->getcfg();
		$res=imap_renamemailbox($this->imap_stream,$ref.self::encode_utf7($old_mailbox),$ref.self::encode_utf7($new_mailbox));
		return $res;
	}

	public function subscribe($mailbox){
		if(!$this->is_opened()) return false;
		$res=imap_subscribe($this->imap_stream,$this->getcfg().self::encode_utf7($mailbox));
		return $res;
	}

	public function unsubscribe($mailbox){
		if(!$this->is_opened()) return false;
		$res=imap_unsubscribe($this->imap_stream,$this->getcfg().self::encode_utf7($mailbox));
		return $res;
	}

	/**
	 * @param $criteria
	 * @param string $charset
	 * @return array|bool
	 * @see imap_search()
	 */
	public function search($criteria,$charset='UTF-8'){
		if(!$this->is_opened()) return false;
		$res=imap_search($this->imap_stream, $criteria,SE_FREE,$charset);
		return $res;
	}

	/**
	 * @param $criteria
	 * @param string $charset
	 * @return array|bool
	 * @see imap_search()
	 */
	public function search_uid($criteria,$charset='UTF-8'){
		if(!$this->is_opened()) return false;
		$res=imap_search($this->imap_stream, $criteria,SE_UID,$charset);
		return $res;
	}

	/**
	 * @param string $search_criteria
	 * @param int $criteria
	 * @param int $reverse
	 * @param string $charset
	 * @return array|bool
	 * @see imap_sort()
	 */
	public function sort($search_criteria, int $criteria, int $reverse=0, $charset='UTF-8'){
		if(!$this->is_opened()) return false;
		$res=imap_sort($this->imap_stream, $criteria, $reverse, SE_NOPREFETCH, $search_criteria, $charset);
		return $res;
	}

	/**
	 * @param string $search_criteria
	 * @param int $criteria
	 * @param int $reverse
	 * @param string $charset
	 * @return array|bool
	 * @see imap_sort()
	 */
	public function sort_uid(string $search_criteria, int $criteria, int $reverse=0, $charset='UTF-8'){
		if(!$this->is_opened()) return false;
		$res=imap_sort($this->imap_stream, $criteria, $reverse, SE_NOPREFETCH | SE_UID, $search_criteria, $charset);
		return $res;
	}

	public function getMsgUID($msg_number){
		if(!$this->is_opened()) return false;
		$res=imap_uid($this->imap_stream, $msg_number);
		return $res;
	}

	public function getMsgNumber($msg_uid){
		if(!$this->is_opened()) return false;
		$res=imap_msgno($this->imap_stream, $msg_uid);
		return $res;
	}

	public static function &mime_decode(&$var,$enc){
		if($enc==ENC7BIT){
			//$var=imap_utf7_decode($var);
		}elseif($enc==ENC8BIT){
			//$var=imap_base64(imap_binary($var));
		}elseif($enc==ENCBINARY){
			//$var=imap_binary($var);
		}elseif($enc==ENCBASE64){
			$var=imap_base64($var);
		}elseif($enc==ENCQUOTEDPRINTABLE){
			$var=imap_qprint($var);
		}elseif($enc==ENCOTHER){
		}else{
		}
		return $var;
	}

	public static function &header_decode(&$var){
		if(is_array($var)){
			foreach($var AS $k=>&$v){
				$var[$k]=&self::header_decode($v);
			}
		}elseif(is_object($var)){
			foreach($var AS $k=>&$v){
				$var->$k=&self::header_decode($v);
			}
		}elseif(is_string($var)){
			$mime=imap_mime_header_decode($var);
			if($mime===false){
				// @link http://www.faqs.org/rfcs/rfc2047.html
				$var=preg_replace("/(\=\?[^\?]+\?[bB]\?)([^\?]*[^=])[=]+(\?\=)/", "$1$2$3", $var);
				$mime=imap_mime_header_decode($var);
			}
			if($mime!==false){
				$var='';
				foreach($mime AS $item){
					$var.=mb_convert_encoding($item->text, 'utf-8', mb_detect_encoding($item->text));
				}
			}
		}
		return $var;
	}

	protected static function add_extra_data(&$structure, $section='') {
		if(isset($structure->has_extra_data)){
			return;
		}
		$structure->has_extra_data=true;
		$structure->mime_type=self::get_mime_type($structure);
		$structure->params=self::getParams_Structure($structure);
		if(strlen($section)==0){
			if (is_countable($structure->parts ?? null) && count($structure->parts) > 0) {    // There some sub parts
				foreach ($structure->parts as $count => $part) {
					self::add_extra_data($part, ($count+1));
				}
			}else{    // Email does not have a seperate mime attachment for text
				$structure->section_id='1';
			}
			return;
		}
		$structure->section_id=$section;
		if ($structure->type == 2) { // Check to see if the part is an attached email message, as in the RFC-822 type
			//print_r($structure);
			if (is_countable($structure->parts ?? null) && count($structure->parts) > 0) {    // Check to see if the email has parts
				foreach ($structure->parts as $count => &$part) {
					// Iterate here again to compensate for the broken way that imap_fetchbody() handles attachments
					if (is_countable($part->parts ?? null) && count($part->parts) > 0) {
						foreach ($part->parts as $count2 => &$part2) {
							self::add_extra_data($part2, $section.".".($count2+1));
						}
					}else{    // Attached email does not have a seperate mime attachment for text
						$part->section_id=$section.'.'.($count+1);
					}
				}
			}else{    // Not sure if this is possible
				$structure->section_id='1';
			}
		}else{    // If there are more sub-parts, expand them out.
			if (is_countable($structure->parts ?? null) && count($structure->parts) > 0) {
				foreach ($structure->parts as $count => &$p) {
					self::add_extra_data($p, $section.".".($count+1));
				}
			}
		}
	}

	/**
	 * Este es el valor devuelto por {@see MailBox::get_mime_type()} si no se puede identificar el mime type de una estructura del correo
	 * @var string
	 */
	public static $mime_type_default='application/octet-stream';

	protected static function get_mime_type(stdClass &$structure){
		$type_list=array(
			TYPETEXT=>'text',
			TYPEMULTIPART=>'multipart',
			TYPEMESSAGE=>'message',
			TYPEAPPLICATION=>'application',
			TYPEAUDIO=>'audio',
			TYPEIMAGE=>'image',
			TYPEVIDEO=>'video',
			TYPEMODEL=>'model',
			TYPEOTHER=>'other',
		);
		if(!isset($type_list[$structure->type]) || empty($structure->subtype)){
			return strtolower(self::$mime_type_default);
		}
		return strtolower($type_list[$structure->type].'/'.$structure->subtype);
	}

	protected static function &list_Attachments(&$structure,array &$list=array()){
		if(!is_object($structure) || !is_a($structure, 'stdClass')){
			return $list;
		}
		if(isset($structure->parts) && is_array($structure->parts)){
			foreach($structure->parts AS &$part){
				if(is_object($part) && is_a($part, 'stdClass')){
					if(isset($part->parts)){
						self::list_Attachments($part, $list);
					}else{
						if(is_array($part->params)){
							if(($part->ifdisposition && $part->disposition=='attachment') || isset($part->params['name']) || isset($part->params['filename']) || isset($part->params['filename*'])){
								$list[]=&$part;
							}
						}
					}
				}
			}
		}
		return $list;
	}

	protected static function &getParams_Structure(&$structure){
		$params=array();
		if(!is_object($structure) || !is_a($structure, 'stdClass')){
			return $params;
		}
		if(isset($structure->parameters) && is_array($structure->parameters)){
			foreach($structure->parameters AS &$param){
				if(is_object($param) && isset($param->attribute) && isset($param->value)){
					$params[$param->attribute]=$param->value;
				}
			}
		}
		if(isset($structure->dparameters) && is_array($structure->dparameters)){
			foreach($structure->dparameters AS &$param){
				if(is_object($param) && isset($param->attribute) && isset($param->value)){
					$params[$param->attribute]=$param->value;
				}
			}
		}
		foreach($params AS $name=>&$value){
			if($name && preg_match("/^.*[*]$/",$name)){
				$matches=array();
				if(preg_match("/([^']+)[']([^']*)['](.+)$/",$value,$matches)){
					$value=urldecode($matches[3]);
					$value=mb_convert_encoding($value,'utf-8',mb_detect_encoding($value));
				}
			}
		}
		return $params;
	}

	/**
	 * @param $msg_number
	 * @return array|bool
	 * @see MailBox::getStructure()
	 */
	public function getAttachments($msg_number){
		$structure=$this->getStructure($msg_number);
		if(!$structure) return false;
		$list=self::list_Attachments($structure);
		return $list;
	}

	/**
	 * @param $msg_uid
	 * @return array|bool
	 * @see MailBox::getStructure_uid()
	 */
	public function getAttachments_uid($msg_uid){
		$structure=$this->getStructure_uid($msg_uid);
		if(!$structure) return false;
		$list=self::list_Attachments($structure);
		return $list;
	}

	/**
	 * @param $msg_number
	 * @return bool|object
	 * @see imap_fetchstructure()
	 */
	public function getStructure($msg_number){
		if(!$this->is_opened()) return false;
		$res=imap_fetchstructure($this->imap_stream,$msg_number,0);
		if($res){
			self::header_decode($res);
			self::add_extra_data($res);
		}
		return $res;
	}

	/**
	 * @param $msg_uid
	 * @return bool|object
	 * @see imap_fetchstructure()
	 */
	public function getStructure_uid($msg_uid){
		if(!$this->is_opened()) return false;
		$res=imap_fetchstructure($this->imap_stream,$msg_uid,FT_UID);
		if($res){
			self::header_decode($res);
			self::add_extra_data($res);
		}
		return $res;
	}

	/**
	 * @param $msg_number
	 * @return bool|object
	 * @see imap_bodystruct()
	 */
	public function getBodyStructure($msg_number,$section){
		if(!$this->is_opened()) return false;
		$res=imap_bodystruct($this->imap_stream,$msg_number,$section);
		if($res){
			self::header_decode($res);
			self::add_extra_data($res);
		}
		return $res;
	}

	public function getBodySection($msg_number,$section,$decodemime=false){
		if(!$this->is_opened()) return false;
		$res=imap_fetchbody($this->imap_stream,$msg_number,$section);
		if($decodemime && $res){
			$d=$this->getBodyStructure($msg_number, $section);
			$res=self::mime_decode($res,$d->encoding);
		}
		return $res;
	}

	public function getBodySection_uid($msg_uid,$section,$decodemime=false){
		if(!$this->is_opened()) return false;
		$res=imap_fetchbody($this->imap_stream,$msg_uid,$section,FT_UID);
		if($decodemime && $res){
			$d=$this->getBodyStructure($this->getMsgNumber($msg_uid), $section);
			$res=self::mime_decode($res,$d->encoding);
		}
		return $res;
	}

	/**
	 * @param $msg_number
	 * @param string $section
	 * @param string $file
	 * @return bool|int
	 * @see MailBox::getBodySection()
	 * @see file_put_contents()
	 */
	public function saveBodySection($msg_number,$section,$file){
		$res=$this->getBodySection($msg_number, $section,true);
		if($res && is_string($file)){
			return file_put_contents($file,$res);
		}
		return false;
	}

	/**
	 * @param $msg_uid
	 * @param string $section
	 * @param string $file
	 * @return bool|int
	 * @see MailBox::getBodySection_uid()
	 * @see file_put_contents()
	 */
	public function saveBodySection_uid($msg_uid,$section,$file){
		$res=$this->getBodySection_uid($msg_uid, $section,true);
		if($res && is_string($file)){
			return file_put_contents($file,$res);
		}
		return false;
	}

	public function getBody($msg_number){
		if(!$this->is_opened()) return false;
		$res=imap_body($this->imap_stream,$msg_number);
		return $res;
	}

	public function getBody_uid($msg_uid){
		if(!$this->is_opened()) return false;
		$res=imap_body($this->imap_stream,$msg_uid,FT_UID);
		return $res;
	}

	/**
	 * @param $msg_number
	 * @param bool $to_matrix Default: TRUE {@see MailBox::headers_to_matrix()}
	 * @return bool|string|array
	 */
	public function getHeader($msg_number, $to_matrix=true){
		if(!$this->is_opened()) return false;
		$res=imap_fetchheader($this->imap_stream,$msg_number);
		if($to_matrix){
			return self::headers_to_matrix($res);
		}
		return $res;
	}

	/**
	 * @param $msg_uid
	 * @param bool $to_matrix Default: TRUE {@see MailBox::headers_to_matrix()}
	 * @return bool|string|array
	 */
	public function getHeader_uid($msg_uid, $to_matrix=true){
		if(!$this->is_opened()) return false;
		$res=imap_fetchheader($this->imap_stream,$msg_uid,FT_UID);
		if($to_matrix){
			return self::headers_to_matrix($res);
		}
		return $res;
	}

	/**
	 * @param $msg_number
	 * @return array|bool
	 * @see imap_headerinfo()
	 */
	public function getHeaderInfo($msg_number){
		if(!$this->is_opened()) return false;
		$res=imap_headerinfo($this->imap_stream,$msg_number);
		return self::header_decode($res);
	}

	/**
	 * @param $msg_number
	 * @return array|bool
	 * @see imap_fetch_overview()
	 */
	public function getOverView($msg_number){
		if(!$this->is_opened()) return false;
		$res=imap_fetch_overview($this->imap_stream,$msg_number,0);
		return self::header_decode($res);
	}

	/**
	 * @param $msg_number
	 * @return array|bool
	 * @see imap_fetch_overview()
	 */
	public function getOverView_uid($msg_uid){
		if(!$this->is_opened()) return false;
		$res=imap_fetch_overview($this->imap_stream,$msg_uid,FT_UID);
		return self::header_decode($res);
	}

	public static function &headers_to_matrix($header_str){
		$list=explode("\n",$header_str);
		$matrix=array();
		$last_name=null;
		$last_value=null;
		foreach($list AS &$l){
			if(preg_match("/^([^\s]+):(.*)/",$l,$matches)){
				if(!is_null($last_name)){
					$matrix[]=array(
						'name'=>$last_name,
						'value'=>self::header_decode($last_value)
					);
				}
				$last_name=$matches[1];
				$last_value=$matches[2];
			}else{
				$last_value.="\n".$l;
			}
		}
		if(!is_null($last_name)){
			$matrix[]=array(
				'name'=>$last_name,
				'value'=>self::header_decode($last_value)
			);
		}
		return $matrix;
	}

	/**
	 * @param $msg_number
	 * @return bool|null
	 * @see MailBox::getHeaderInfo()
	 */
	public static function getSubject(stdClass $header_info){
		$resp=null;
		if(isset($header_info->subject)){
			$resp=$header_info->subject;
		}
		return $resp;
	}

	/**
	 * Devuelve la lista de los correos a los que se entregó el mensaje
	 * @param $header_str
	 * @return array
	 * @see MailBox::getReceived_for() Lista complementaria
	 */
	public static function getDeliveredTo($header_str){
		$list=self::getParamsHeader($header_str, 'Delivered-To');
		foreach($list AS &$item){
			$item=explode('@',trim($item));
			if(count($item)==2){
				$item[0]=str_replace($item[1].'-', '', $item[0]);
				$item=implode('@',$item);
				if(!preg_match('/^'.self::REGEXP_MAIL_ADDRESS.'$/',$item)){
					$item='';
				}
			}else{
				$item='';
			}
		}
		return $list;
	}

	/**
	 * Devuelve la lista de los correos que recibieron el mensaje, según la información de los encabezados <b>Received</b>
	 * @param $header_str
	 * @return array
	 * @see MailBox::getDeliveredTo() Lista complementaria
	 */
	public static function getReceived_for($header_str){
		$recieved=self::getParamsHeader($header_str, 'Received');
		$list=array();
		foreach($recieved AS $item){
			if(preg_match('/\bfor\b\s+(\<?)('.self::REGEXP_MAIL_ADDRESS.')(\>?)[;\s]/', $item.PHP_EOL, $matches)){
				if(($matches[1].$matches[3])=='' || ($matches[1].$matches[3])=='<>'){
					$list[]=$matches[2];
				}
			}
		}
		return $list;
	}

	/**
	 * Devuelve la lista de los correos que redirigieron el mensaje
	 * @param $header_str
	 * @return array
	 */
	public static function getResentFrom($header_str){
		$list=array();
		$items=self::getParamsHeader($header_str, 'Resent-From');
		foreach($items AS &$item){
			$matches=array();
			preg_match_all('/<([^>]*)>/',$item,$matches);
			$list=array_merge($list,$matches[1]);
		}
		return $list;
	}

	/**
	 * Devuelve la lista de correos del encabezado Reply-To
	 * @param $msg_number
	 * @return array
	 */
	public function get_real_ReplyTo_list($msg_number){
		$list=array();
		$header_info=$this->getHeaderInfo($msg_number);
		if(!$header_info) return $list;
		$headers=$this->getHeader($msg_number);
		if(!$headers) return $list;
		if(count(MailBox::getParamsHeader($headers, 'Reply-To'))>0){
			$list=MailBox::getReplyTo_list($header_info);
		}
		return $list;
	}

	/**
	 * Devuelve la lista de correos del encabezado Return-Path
	 * @param $msg_number
	 * @return array
	 */
	public function get_real_ReturnPath_list($msg_number){
		$list=array();
		$headers=$this->getHeader($msg_number);
		if(!$headers) return $list;
		$return_path=MailBox::getParamsHeader($headers, 'Return-Path');
		if(count($return_path)){
			foreach($return_path AS $mail){
				$mail=filter_var($mail,FILTER_VALIDATE_EMAIL);
				if($mail){
					$list[]=$mail;
				}
			}
		}
		return $list;
	}

	/**
	 * Alias de {@see MailBox::get_real_ReplyTo_list()}
	 * @param $msg_uid
	 * @return array
	 */
	public function get_real_ReplyTo_list_uid($msg_uid){
		return $this->get_real_ReplyTo_list($this->getMsgNumber($msg_uid));
	}

	/**
	 * Alias de {@see MailBox::get_real_ReturnPath_list()}
	 * @param $msg_uid
	 * @return array
	 */
	public function get_real_ReturnPath_list_uid($msg_uid){
		return $this->get_real_ReturnPath_list($this->getMsgNumber($msg_uid));
	}

	public static function isAutoSubmitted($header_str){
		$val=self::getParamsHeader($header_str, 'auto-submitted');
		if(count($val)>0 && in_array(strtolower($val[0]),array('auto-generated','auto-replied'))){
			return true;
		}
		return false;
	}

	public static function isXAutoResponseSuppress($header_str){
		$val=self::getParamsHeader($header_str, 'X-Auto-Response-Suppress');
		if(count($val)>0){
			foreach($val AS $v){
				if(substr_count($v,'AutoReply')>0 || substr_count($v,'All')>0){
					return true;
				}
			}
		}
		return false;
	}

	public static function getParamsHeader($header_str,$param_name){
		if(!is_array($header_str)){
			$header_str=self::headers_to_matrix($header_str);
		}
		$list=array();
		foreach($header_str AS &$item){
			if(is_array($item) && $item['name']==$param_name){
				$list[]=trim($item['value']);
			}
		}
		return $list;
	}

	/**
	 * @param stdClass $header_info
	 * @return array
	 * @see MailBox::getHeaderInfo()
	 */
	public static function getFrom_list(stdClass $header_info){
		$list=array();
		if(isset($header_info->from)){
			foreach($header_info->from AS $mail){
				$mail=trim($mail->mailbox).'@'.trim($mail->host);
				if($mail!='@') $list[]=$mail;
				else $list[]='';
			}
		}
		return $list;
	}

	/**
	 * @param stdClass $header_info
	 * @return array
	 * @see MailBox::getHeaderInfo()
	 */
	public static function getReturnPath_list(stdClass $header_info){
		$list=array();
		if(isset($header_info->return_path)){
			foreach($header_info->return_path AS $mail){
				$mail=trim($mail->mailbox).'@'.trim($mail->host);
				if($mail!='@') $list[]=$mail;
				else $list[]='';
			}
		}
		return $list;
	}

	/**
	 * @param stdClass $header_info
	 * @return array
	 * @see MailBox::getHeaderInfo()
	 */
	public static function getSender_list(stdClass $header_info){
		$list=array();
		if(isset($header_info->sender)){
			foreach($header_info->sender AS $mail){
				$mail=trim($mail->mailbox).'@'.trim($mail->host);
				if($mail!='@') $list[]=$mail;
				else $list[]='';
			}
		}
		return $list;
	}

	/**
	 * @param stdClass $header_info
	 * @return array
	 * @see MailBox::getHeaderInfo()
	 */
	public static function getReplyTo_list(stdClass $header_info){
		$list=array();
		if(isset($header_info->reply_to)){
			foreach($header_info->reply_to AS $mail){
				$mail=trim($mail->mailbox).'@'.trim($mail->host);
				if($mail!='@') $list[]=$mail;
				else $list[]='';
			}
		}
		return $list;
	}

	/**
	 * @param stdClass $header_info
	 * @return array
	 * @see MailBox::getHeaderInfo()
	 */
	public static function getTo_list(stdClass $header_info){
		$list=array();
		if(isset($header_info->to)){
			foreach($header_info->to AS $mail){
				$mail=trim($mail->mailbox).'@'.trim($mail->host);
				if($mail!='@') $list[]=$mail;
				else $list[]='';
			}
		}
		return $list;
	}

	/**
	 * @param stdClass $header_info
	 * @return array
	 * @see MailBox::getHeaderInfo()
	 */
	public static function getCc_list(stdClass $header_info){
		$list=array();
		if(isset($header_info->cc)){
			foreach($header_info->cc AS $mail){
				$mail=trim($mail->mailbox).'@'.trim($mail->host);
				if($mail!='@') $list[]=$mail;
				else $list[]='';
			}
		}
		return $list;
	}

	/**
	 * @param stdClass $header_info
	 * @return array
	 * @see MailBox::getHeaderInfo()
	 */
	public static function getBcc_list(stdClass $header_info){
		$list=array();
		if(isset($header_info->bcc)){
			foreach($header_info->bcc AS $mail){
				$mail=trim($mail->mailbox).'@'.trim($mail->host);
				if($mail!='@') $list[]=$mail;
				else $list[]='';
			}
		}
		return $list;
	}

	/**
	 * Valida si un correo ha sido leido o no, basandose en la información de los encabezados
	 * @param stdClass $header_info
	 * @return bool
	 * @see MailBox::getHeaderInfo()
	 */
	public static function isSeen(stdClass $header_info){
		if($header_info->Recent=='R'){
			return true;
		}elseif($header_info->Recent=='N'){
			return false;
		}
		if($header_info->Unseen=='U'){
			return false;
		}elseif($header_info->Unseen==''){
			return true;
		}
		return false;
	}

	/**
	 * Valida si un correo ha sido marcado o no, basandose en la información de los encabezados
	 * @param stdClass $header_info
	 * @return bool
	 * @see MailBox::getHeaderInfo()
	 */
	public static function isFlagged(stdClass $header_info){
		if($header_info->Flagged=='F'){
			return true;
		}
		return false;
	}

	/**
	 * Copia el mensaje a otro mailbox.
	 * @param $msglist
	 * @param $to_mailbox
	 * @return bool
	 * @see imap_mail_copy()
	 */
	public function copy($msglist, $to_mailbox){
		if(!$this->is_opened()) return false;
		$res=imap_mail_copy($this->imap_stream,$msglist,$to_mailbox);
		return $res;
	}

	/**
	 * Copia el mensaje a otro mailbox.
	 * @param $msglist
	 * @param $to_mailbox
	 * @return bool
	 * @see imap_mail_copy()
	 */
	public function copy_uid($msglist, $to_mailbox){
		if(!$this->is_opened()) return false;
		$res=imap_mail_copy($this->imap_stream,$msglist,$to_mailbox,CP_UID);
		return $res;
	}

	/**
	 * Mover el mensaje a otro mailbox. Usar {@see MailBox::expunge()} para aplicar los cambios
	 * @param $msglist
	 * @param $to_mailbox
	 * @return bool
	 * @see imap_mail_move()
	 */
	public function move($msglist, $to_mailbox){
		if(!$this->is_opened()) return false;
		$res=imap_mail_move($this->imap_stream,$msglist,$to_mailbox,0);
		return $res;
	}

	/**
	 * Mover el mensaje a otro mailbox. Usar {@see MailBox::expunge()} para aplicar los cambios
	 * @param $msglist
	 * @param $to_mailbox
	 * @return bool
	 * @see imap_mail_move()
	 */
	public function move_uid($msglist, $to_mailbox){
		if(!$this->is_opened()) return false;
		$res=imap_mail_move($this->imap_stream,$msglist,$to_mailbox,CP_UID);
		return $res;
	}

	/**
	 * @param $sequence
	 * @param $flag
	 * @return bool
	 * @see imap_setflag_full()
	 */
	protected function addflag($sequence,$flag){
		if(!$this->is_opened()) return false;
		$res=imap_setflag_full($this->imap_stream, $sequence, $flag,NIL);
		return $res;
	}

	/**
	 * @param $sequence
	 * @param $flag
	 * @return bool
	 * @see imap_setflag_full()
	 */
	protected function addflag_uid($sequence,$flag){
		if(!$this->is_opened()) return false;
		$res=imap_setflag_full($this->imap_stream,$sequence,$flag,ST_UID);
		return $res;
	}

	/**
	 * @param $sequence
	 * @param $flag
	 * @return bool
	 * @see imap_clearflag_full()
	 */
	protected function removeflag($sequence,$flag){
		if(!$this->is_opened()) return false;
		$res=imap_clearflag_full($this->imap_stream, $sequence, $flag);
		return $res;
	}

	/**
	 * @param $sequence
	 * @param $flag
	 * @return bool
	 * @see imap_clearflag_full()
	 */
	protected function removeflag_uid($sequence,$flag){
		if(!$this->is_opened()) return false;
		$res=imap_clearflag_full($this->imap_stream,$sequence,$flag,ST_UID);
		return $res;
	}

	public function setSeen($sequence){
		return $this->addflag($sequence,'\Seen');
	}

	public function setUnSeen($sequence){
		return $this->removeflag($sequence,'\Seen');
	}

	public function setSeen_uid($sequence){
		return $this->addflag_uid($sequence,'\Seen');
	}

	public function setUnSeen_uid($sequence){
		return $this->removeflag_uid($sequence,'\Seen');
	}

	public function setAnswered($sequence){
		return $this->addflag($sequence,'\Answered');
	}

	public function setUnAnswered($sequence){
		return $this->removeflag($sequence,'\Answered');
	}

	public function setAnswered_uid($sequence){
		return $this->addflag_uid($sequence,'\Answered');
	}

	public function setUnAnswered_uid($sequence){
		return $this->removeflag_uid($sequence,'\Answered');
	}

	public function setFlagged($sequence){
		return $this->addflag($sequence,'\Flagged');
	}

	public function setUnFlagged($sequence){
		return $this->removeflag($sequence,'\Flagged');
	}

	public function setFlagged_uid($sequence){
		return $this->addflag_uid($sequence,'\Flagged');
	}

	public function setUnFlagged_uid($sequence){
		return $this->removeflag_uid($sequence,'\Flagged');
	}

	/**
	 * Marca los correos para ser eliminados. Usar {@see MailBox::expunge()} para aplicar los cambios
	 * @param $sequence
	 * @return bool
	 */
	public function setDeleted($sequence){
		return $this->addflag($sequence,'\Deleted');
	}

	/**
	 * Desmarca los correos para ser eliminados.
	 * @param $sequence
	 * @return bool
	 */
	public function setUnDeleted($sequence){
		return $this->removeflag($sequence,'\Deleted');
	}

	public function setDeleted_uid($sequence){
		return $this->addflag_uid($sequence,'\Deleted');
	}

	public function setUnDeleted_uid($sequence){
		return $this->removeflag_uid($sequence,'\Deleted');
	}

	public function setDraft($sequence){
		return $this->addflag($sequence,'\Draft');
	}

	public function setUnDraft($sequence){
		return $this->removeflag($sequence,'\Draft');
	}

	public function setDraft_uid($sequence){
		return $this->addflag_uid($sequence,'\Draft');
	}

	public function setUnDraft_uid($sequence){
		return $this->removeflag_uid($sequence,'\Draft');
	}

}
