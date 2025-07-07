<?php

namespace rsvitak\labdoc;

use tecnickcom\TCPDF;

// Extend the TCPDF class to create custom Header and Footer
class LabPdf extends \TCPDF {
    private $opts=[];
    private $labDoc=null;
    private $doCache=true;
    private $doSign=true;
    private $logo=null;
    private $md5sum=null;
    private $mtime=null;
    
    public function __construct($labDoc) {
        parent::__construct(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $labweb=defined('APPLICATION_PATH');
        $this->opts['CACHE_STORAGE']=$labweb ? LABPDF_CACHE_STORAGE : $_ENV['LABPDF_CACHE_STORAGE'];
        $this->opts['TEMP_DIR']=$labweb ? APPLICATION_PATH_TMP : $_ENV['LABPDF_TEMP_DIR'];
        $this->opts['JAVA_PATH']=$labweb ? JAVA_PATH : $_ENV['JAVA_PATH'];
        $this->opts['DSS_CLI_APP']=$labweb ? DSS_CLI_APP : $_ENV['DSS_CLI_APP'];
        $this->opts['LABIN_PKEY_P12']=$labweb ? LABIN_PKEY_P12 : $_ENV['LABIN_PKEY_P12'];
        $this->opts['LABIN_PKEY_P12_PASSWORD']=$labweb ? LABIN_PKEY_P12_PASSWORD : $_ENV['LABIN_PKEY_P12_PASSWORD'];
        $this->opts['TSA_URL']=$labweb ? TSA_URL : $_ENV['TSA_URL'];
        $this->opts['TSA_USERNAME']=$labweb ? TSA_USERNAME : $_ENV['TSA_USERNAME'];
        $this->opts['TSA_PASSWORD']=$labweb ? TSA_PASSWORD : $_ENV['TSA_PASSWORD'];
        $this->opts['LOGO_DIR']=$labweb ? APPLICATION_PATH.'httpd/htdocs/images/new/' : $_ENV['LABPDF_LOGO_DIR'];

        $this->labDoc=$labDoc;
        if ($domain=$this->labDoc->getDomain()) {
            if ($domain!='LIN') {
               $this->doCache=false;
               $this->doSign=false;
            }
        } else throw new \Exception('Unable to create LabPdf file due to the missing domain');
        $this->setLogo(\rtrim($this->opts['LOGO_DIR'], '/').'/logo-'.(\strtolower($domain)).'-sm-cz-hr.png');

        switch ((new \ReflectionClass($this->labDoc))->getShortName()) {
        case 'LabTestResultDoc':
            $this->setTitle('Výsledky laboratorního vyšetření');
            break;

        case 'LabTestRequestDoc':
            $this->setTitle('Žádanka na laboratorní vyšetření');
            break;

        default: 
            $this->setTitle('Laboratorní dokument');
            break;
        }//switch
    }

    public function setDoCache(bool $doCache) {
        $this->doCache=$doCache;
        return $this;
    }

    public function setDoSign(bool $doSign) {
        if (!($this->doSign=$doSign)) $this->setDoCache(false);
        return $this;
    }

    public function getOutput() {
        $outputFileUnsigned=$outputFileSigned=null;

        if ($this->doCache && $this->isCached()) {
            $outputFile=$this->getPathInCacheStorage();
        } else {
            //not cached,, generate the file
            $outputFileUnsigned=tempnam($this->opts['TEMP_DIR'], '_unsigned_pdf_').'.pdf';
            $tcpdf_output=$this->Output($outputFileUnsigned, 'F');
            if ($this->doSign) {
               $outputFileSigned=$this->doCache ? $this->getPathInCacheStorage() : tempnam($this->opts['TEMP_DIR'], '_signed_pdf_').'.pdf';
               \is_dir(dirname($outputFileSigned)) || \mkdir(dirname($outputFileSigned), 0777, true);
               $cmd=$this->opts['JAVA_PATH'].' -jar '.$this->opts['DSS_CLI_APP'].' '.$outputFileUnsigned.' '.$outputFileSigned.' '.$this->opts['LABIN_PKEY_P12'].' '.$this->opts['LABIN_PKEY_P12_PASSWORD'].' '.$this->opts['TSA_URL'].' '.$this->opts['TSA_USERNAME'].' '.$this->opts['TSA_PASSWORD'].' 2>&1';
               exec($cmd, $dss_cli_app_output, $rc);
   
               if ($rc!='0') {
                   $labweb=defined('APPLICATION_PATH');
                   if ($labweb) labweb_log($cmd.PHP_EOL.implode(PHP_EOL, $dss_cli_app_output));
                   else dump([$cmd, $dss_cli_app_output]);
                   file_exists($outputFileSigned) && unlink($outputFileSigned);
                   $outputFileSigned=null;
               }
               $outputFile=$outputFileSigned;
            } else {
               $outputFile=$outputFileUnsigned;
            }
        }
        if ($outputFile) {
           $result=\file_get_contents($outputFile);
           $this->md5sum=\md5_file($outputFile);
           $this->mtime=(new \DateTime('@'.\filemtime($outputFile)))->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d\TH:i:s');
           $outputFileUnsigned && unlink($outputFileUnsigned);
           !$this->doCache && $outputFileSigned && unlink($outputFileSigned);
        } else {
           $result=null;
        }
        return $result;
    }

    public function getVersionInfo() {
        return $this->mtime && $this->md5sum ? 'mtime='.$this->mtime.'&md5='.$this->md5sum : '';
    }

    public function getTitle() {
        return $this->title;
    }

    public function setTitle($title) {
        $this->title=$title;
        return $this;
    }

    public function getSubject() {
        return $this->subject;
    }

    public function setSubject($subject) {
        $this->subject=$subject;
        return $this;
    }

    public function setLogo($logo) {
        $this->logo=$logo;
        return $this;
    }

    //Page header
    public function header() {
       //Logo
        if ($this->logo) {
        dump($this->logo);
           $image_file=$this->logo; //FIXME: the logo should be somewhere in "etc", "share" or "config" folder?
           $this->Image($image_file, 20, 10, 0, 15, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }
        // Set font
        $this->SetFont('dejavusans', 'B', 8);
        // Title
        $html='<h1>'.htmlspecialchars($this->getTitle()).'</h1>';
        $this->MultiCell(0, 0, $html, 0, '', false, 0, $this->getX()+10, $this->getY()+5, true, 0, true);

    }

    // Page footer
    public function footer() {
        // Position at 15 mm from bottom
        $this->SetY(-15);
        // Set font
        $this->SetFont('dejavusans', 'N', 7);
        switch ($this->labDoc->getDomain()) {
        case 'LIN' :
            if ($this->labDoc->getIco()=='28005902') {
                $html='VARAPALO&nbsp;s.r.o., IČ:&nbsp;28005902, nám.&nbsp;Dr.&nbsp;M.&nbsp;Horákové&nbsp;1313/8,&nbsp;360&nbsp;01 Karlovy&nbsp;Vary<br>Zelená linka&nbsp;800&nbsp;183&nbsp;675<br>e-mail:&nbsp;<a href="mailto:operator@labin.cz">operator@labin.cz</a>, www:&nbsp;<a href="https://labin.cz">labin.cz</a>';
            } else {
                $html='Lab&nbsp;In&nbsp;-&nbsp;Institut&nbsp;laboratorní&nbsp;medicíny,&nbsp;s.r.o., IČ:&nbsp;25230271, Blahoslavova&nbsp;18/5, 360&nbsp;01&nbsp;Karlovy&nbsp;Vary<br>Zelená linka&nbsp;800&nbsp;183&nbsp;675, 800&nbsp;100&nbsp;590, tel.&nbsp;353&nbsp;311&nbsp;514<br>e-mail:&nbsp;<a href="mailto:operator@labin.cz">operator@labin.cz</a>, www:&nbsp;<a href="https://labin.cz">labin.cz</a>';
            }
            break;

        case 'CTL' : 
            $html='CITYLAB&nbsp;s.r.o., IČ:&nbsp;28442156, Seydlerova&nbsp;2451/8, 158&nbsp;00&nbsp;Praha&nbsp;5<br>Zelená linka&nbsp;800&nbsp;801&nbsp;811,<br>e-mail:&nbsp;<a href="mailto:citylab@citylab.cz">citylab@citylab.cz</a>, www:&nbsp;<a href="https://citylab.cz/">citylab.cz</a>';
            break;
        }//switch
        //$this->Cell(0, 0, "Lab In - Institut laboratorní medicíny, s.r.o., Bezručova 10, 360 01 Karlovy Vary\nZelená linka 800 183 675, 800 100 590, tel. 353 311 514\ne-mail: operator@labin.cz, www.labin.cz", 0, false, 'C', 0, '', 0, false, 'T', 'M');
        $this->MultiCell(150, 0, $html, 0, 'C', false, 0, null, null, true, 0, true);

        // Page number
        $this->Cell(0, 10, $this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
        // print a blox of text using multicell()
    }

    public function loadHtml($html) {
      if (trim($html)=='') return;

      // set document information
      $this->SetCreator(PDF_CREATOR);
      $this->SetAuthor('Lab In - Institut laboratorní medicíny'); //FIXME
      $this->SetTitle($this->getTitle());
      $this->SetSubject($this->getSubject());
      $this->SetKeywords('TCPDF, PDF, TSA, TSR, TSQ');

      // set default monospaced font
      $this->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

      // set margins
      $this->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
      $this->SetHeaderMargin(PDF_MARGIN_HEADER);
      $this->SetFooterMargin(PDF_MARGIN_FOOTER);

      // set auto page breaks
      $this->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

      // set image scale factor
      $this->setImageScale(2);//PDF_IMAGE_SCALE_RATIO);
 
      $this->SetFont('dejavusans', '', 10, '', false);

      //https://github.com/tecnickcom/TCPDF/issues/239
      //equals to { padding:0; margin:0 }
      $tagvs=[
         'p'=>    [['h'=>0, 'n'=> 0], ['h' => 0, 'n' => 0]],
         'div'=>  [['h'=>0, 'n'=> 0], ['h' => 0, 'n' => 0]],
         'table'=>[['h'=>0, 'n'=> 0], ['h' => 0, 'n' => 0]],
         'tr'=>   [['h'=>0, 'n'=> 0], ['h' => 0, 'n' => 0]],
         'td'=>   [['h'=>0, 'n'=> 0], ['h' => 0, 'n' => 0]],
         'span'=> [['h'=>0, 'n'=> 0], ['h' => 0, 'n' => 0]],
         'h1'=>   [['h'=>0, 'n'=> 0], ['h' => 0, 'n' => 0]],
         'h2'=>   [['h'=>0, 'n'=> 0], ['h' => 0, 'n' => 0]],
         'h3'=>   [['h'=>0, 'n'=> 0], ['h' => 0, 'n' => 0]],
         'h4'=>   [['h'=>0, 'n'=> 0], ['h' => 0, 'n' => 0]],
      ];
      $this->setHtmlVSpace($tagvs);

      $this->setListIndentWidth(0);

      // add a page
      $this->AddPage();

      // output the HTML content
      $this->writeHTML($html, true, false, false, false, '');

      // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
      // *** set signature appearance ***
      /*
      if ((!$this->sign) || (!isset($this->signature_data['cert_type'])) || empty($this->signature_data_tsa)) {
         $x=$this->getX();
         $y=$this->getY();
         // create content for signature (image and/or text)
         $this->Image('@'.base64_decode($this->stamp_hepnar(true)), $x+120, $y, 60, null, 'JPG');
         // define active area for signature appearance
         $this->setSignatureAppearance($x+120,$y,60,30);
      } else {
         $this->setSignatureAppearance(20,10,50,15);
      }
      */
      // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
      // *** set an empty signature appearance ***
      ///$this->addEmptySignatureAppearance($x, $y, 15, 15);

      // ---------------------------------------------------------
      return $this;
   }


    public function getPathInCacheStorage() {
        if ($this->labDoc->getFileName() && $this->labDoc->getBaseDate()) {
            if (!$this->doCache) return $this->labDoc->getFileName();
            $baseDir=\rtrim($this->opts['CACHE_STORAGE'], '/');
            /*
            $hash=md5($this->getFileName());
            $dir1=substr($hash, 0, 2);
            $dir2=substr($hash, 2, 2);
            return $baseDir.'/'.$dir1.'/'.$dir2.'/'.$this->getFileName();
             */
            switch ((new \ReflectionClass($this->labDoc))->getShortName()) {
            case 'LabTestResultDoc':
               $typeSubDir='vysledky';
               break;
            case 'LabTestRequestDoc':
               $typeSubDir='zadanky';
               break;
            default: 
               $typeSubDir='ostatni';
               break;
            }//switch
            return $baseDir.'/'.$this->labDoc->getBaseDate()->format('Y/m/d').'/'.$typeSubDir.'/'.$this->labDoc->getFileName();
        } 
        throw new \Exception('Missing data to generate storage path');
    }

    public function isCached() {
        return $this->doCache && $this->doSign && ($file=$this->getPathInCacheStorage()) && file_exists($file);
    }

    protected static function stamp_hepnar($base64data_only=false) {
        return ($base64data_only ? '' : '<img id="stamp" style="width:60mm" src="data:image/jpeg;base64,').'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
ABIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABAAB
IAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABAISVE
gAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgAEgAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgBIJUSAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQEgkLIAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAIACAFAkAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEBIJCiQAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQAQglQJAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABBIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAIIZhfQUSAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACCQAAAAAAAAAAAAAAAAAAAAAAAAAAAARCzxZMvpQB
IAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAIBIAAAAAAAAAAAAAAAAAAAAAAAAAAIAPLPOz
tNJVSAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQCQAAAAAAAAAAAAAAAAAAAAAAAAACB
A5bzMdM6VIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABAJAAAAAAAAAAAAAAAAAAAAAA
AAAAICcNT0dc6AkAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgEgAAAAAAAAAAAAAAAA
AAAAAAAAAEHlOW5356VIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABAiaAAAAAAAAAA
AAAAAAAAAAAAAAAAg5XPqX2okAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgEgAAAAA
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
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAknNcgAAAAAAAAAAAAAEEkkAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEzlgAAAAAAAAAAAAAAkAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAkBkAAAAAAAAAAAAEkAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAhcgAAAAAAAAAAAEEgAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAEOUAAAAAAAAAAAEEgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAEjgAAAAAAAAAAgkgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAEEgAAAAAAAAkkkAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAEgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAEkAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAEkkAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAElkAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAkkAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAkNkAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAEfcAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAklNkgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
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'.($base64data_only ? '' : '"/>');
    }


}

