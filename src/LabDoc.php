<?php
/* To get acces to the symfony user provider, it is neccessary to pass the security object to the LabDoc
 * it can be done manually eachtime the LabDoc is created or such src/EventSubscriber/LabDocEventSubscriber.php can be used:
 *
 *
 * <?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Security;
use rsvitak\labdoc\LabDoc;

class LabDocEventSubscriber implements EventSubscriberInterface
{
    private $security; 

    public function __construct(Security $security) {
        $this->security=$security;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        LabDoc::setDefaultUserProvider($this->security);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.request' => 'onKernelRequest',
        ];
    }
}



If someday I need the subscriber in multiple Symfony projects, create a tiny second repo:

labdoc-symfony-bridge/
└── src/
    └── EventSubscriber/
        └── LabDocUserSubscriber.php

composer.json of that bridge would require both:

{
  "name": "rsvitak/labdoc-symfony-bridge",
  "require": {
    "rsvitak/labdoc": "^1.0",
    "symfony/event-dispatcher": "^6.0",
    "symfony/security-core": "^6.0"
  },
  "autoload": {
    "psr-4": { "Labdoc\\Bridge\\Symfony\\": "src/" }
  }
}
*
*
*/

namespace rsvitak\labdoc;

use rsvitak\labdoc\LabPdf;
use Symfony\Component\Security\Core\Security;

abstract class LabDoc {
    private $domain;
    private $ico;
    private $fileName;
    private $baseDate;
    private $accessInfo;
    static private $defaultProvider;

    public function getDomain() : string {
        return $this->domain;
    }

    public function setDomain(string $domain) {
        $this->domain=$domain;
        return $this;
    }
    
    public function getIco() : string {
        return $this->ico;
    }
    
    public function setIco(?string $ico) {
        $this->ico=$ico;
        return $this;
    }

    public function getFileName() : string {
        return $this->fileName;
    }
   
    public function setFileName(string $fileName) {
        $this->fileName=$fileName;
        return $this;
    }

    public function getBaseDate() : \DateTime {
        return $this->baseDate;
    }

    public function setBaseDate(\DateTime $baseDate) {
        $this->baseDate=$baseDate;
        return $this;
    }

    public function getAccessInfo() : string {
        return $this->accessInfo;
    }

    public function setAccessInfo(string $accessInfo) {
        $this->accessInfo=$accessInfo;
        return $this;
    }
    
    public function addAccessInfo(string $accessInfo) {
        $this->setAccessInfo($this->accessInfo===null ? $accessInfo : $this->getAccessInfo().'&'.$accessInfo);
        return $this;
    }

    /** **GLOBAL** setter; call this once during bootstrap */
    public static function setDefaultUserProvider(Security $p): void
    {
        self::$defaultProvider = $p;
    }

    abstract public function getDefaultFileName();

    abstract public function getDefaultAccessLogInfo();

    abstract public function getData(string $format);

    private function accessLog($outputInfo, $accessTime=null) {
        if (\php_sapi_name()=='cli') {
            $requestInfo=(isset($_SERVER['HOSTNAME']) ? $_SERVER['HOSTNAME'] : \gethostname()).':'.$_SERVER['SCRIPT_NAME'];
        } else {
            $requestInfo=$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI']; 
        }
        $accessLogRecord=($accessTime===null ? (new \DateTime(null, new \DateTimeZone('Europe/Prague'))) : $accessTime)->format('Y-m-d\TH:i:s').' '.$outputInfo.' '.$requestInfo.' '.$this->getAccessInfo().PHP_EOL;
        $accessLogFile=defined('APPLICATION_PATH') ? APPLICATION_PATH_LOG.'/labdoc_access.log' : $_ENV['LABDOC_ACCESS_LOG'];
        file_put_contents($accessLogFile, $accessLogRecord, FILE_APPEND);
    }

    public function getOutput($format='pdf', $optionalAccessInfo=null) {
        $accessTime=new \DateTime(null, new \DateTimeZone('Europe/Prague'));
        $format=strtolower(trim($format));
        try {
            if (defined('APPLICATION_PATH')) {
                $accessInfo='is=labweb'.(\php_sapi_name()!='cli' ? '&username='.\get_fir().'.'.\get_username().'&guid='.\get_guid().'&remote_addr='.$_SERVER['REMOTE_ADDR'] : '&user='.$_SERVER['USER']);
            } else {
                $user=(self::$defaultProvider) ? self::$defaultProvider->getUser() : null;
                $accessInfo='is=LabIs'.(\php_sapi_name()!='cli' ? '&username='.($user ? $user->getDomain()->getId().'.'.$user->getNickName().'&id='.$user->getId().'&remote_addr='.$_SERVER['REMOTE_ADDR'] : '') : '&user='.$_SERVER['user']);
            }
        } catch (\Throwable $t) {
           $accessInfo='?userProvider=N/A';
        }
        $this->addAccessInfo($accessInfo);
        $this->addAccessInfo($optionalAccessInfo);
        $this->addAccessInfo($this->getDefaultAccessLogInfo());

        if (in_array($format, ['pdf', 'updf', 'unsigned-pdf', 'pdf-unsigned'])) {
            $labPdf=new LabPdf($this);
            if ($format!=='pdf') {
               $labPdf->setDoSign(false);
            }
            if ($labPdf->isCached()) {
                $outputPdf=$labPdf->getOutput();
                $this->addAccessInfo($labPdf->getVersionInfo())->accessLog($labPdf->getPathInCacheStorage(), $accessTime);
            } else {
               $htmlData=$this->getData('html');
               //file_put_contents('/home/radek/tmp/test.htm', $data);
               $labPdf->loadHtml($htmlData);
               $outputPdf=$labPdf->getOutput();
               $this->addAccessInfo($labPdf->getVersionInfo())->accessLog($labPdf->getPathInCacheStorage(), $accessTime);
            }
            return $outputPdf;
        }

        $this->accessLog(pathinfo($this->getFileName())['filename'].'.'.$format, $accessTime);
        return $this->getData($format);
    }

    static public function getFilenameSafeString($s, $ci=true) {
        //all alhabetical characters small, cut all white space at the beginning and at the end
        //case insensitive - neccesary on windows
        if ($ci) $s=strtolower(trim($s));
        //all whitespace replace by underscore
        $s=preg_replace(';\s+;', '_', $s);
        //all slashes (both types) replace by dot ('.') - substring of slashes replaced by one dot
        $s=preg_replace(';[\\\/]+;', '.', $s);
        //substring of dots replaced by one dot
        $s=preg_replace(';\.+;', '.', $s);
        //special characters replaced by UPPERCASE letter
        $s=strtr($s,
            '@{}$=^+#()?[]~!&%*,:;<>|',
            'ABCDEFGHIJKLMNOPQRSTUVWY'
        );
        //this is neccessary on windows only, but in case of a migration in a future we apply it
        //in UNIX as well
        $s=preg_replace('/^(PRN|CON|AUX|CLOCK$|NUL|COM\d+|LPT\d+)$/im','$0X',str_replace('.',"\n",$s));
        $s=str_replace("\n",'.',$s);
        return $s;
    }

    public function htmlInfoTip($tip) {
        return '<div class="tipbox noprint">'.self::infoTipIcon('padding:0 0.5em 0.5em 0; vertical-align:middle').$tip.'</div>'.PHP_EOL;
    }

    protected function infoTipIcon($css="") {
      return '<img style="'.$css.'" src="data:image/png;base64,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"/>';
    }

}


