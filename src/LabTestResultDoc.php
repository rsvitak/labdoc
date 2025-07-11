<?php
use rsvitak\labdoc\LabDoc;

namespace rsvitak\labdoc;

class LabTestResultDoc extends LabDoc {
    private $xml=null;
    private $dom=null;
    private $xpath=null;
    private $datdu=null;
    private $hcContractId=null;
    private $accessLogDefaultInfo='';

    private $pmZip;
    private $pmATitle;
    private $pmName;
    private $pmSTitle;
    private $pmAddress;
    private $pmCity;
    private $pmTel;
    private $pmICZ;
    private $pmExpertise;
    private $pmCompany;

    public function __construct($labTestResult) {
        $class_name=(new \ReflectionClass($labTestResult))->getShortName() ?? null;
        if ($class_name=='LabTestResult') { //LABIS
            $this->setDomain($labTestResult->getDomain());
            $this->xml=$labTestResult->getXml();
            $this->datdu=\DateTime::createFromImmutable($labTestResult->getSampleAt());
            $this->hcContractId=$labTestResult->getHcContractId();
            $this->pmZip=trim($labTestResult->getDepartment()->getZip());
            $this->pmATitle=$labTestResult->getDepartment()->getAtitle();
            $this->pmName=$labTestResult->getDepartment()->getName();
            $this->pmSTitle=$labTestResult->getDepartment()->getStitle();
            $this->pmAddress=$labTestResult->getDepartment()->getAddress();
            $this->pmCity=$labTestResult->getDepartment()->getCity();
            $this->pmTel=$labTestResult->getDepartment()->getTel();
            $this->pmICZ=$labTestResult->getDepartment()->getIcz();
            $this->pmExpertise=$labTestResult->getDepartment()->getExpertise();
            $this->pmCompany=$this->getDomain()=='CTL' ? 'CITYLAB s.r.o' : 'Lab In - Institut laboratorní medicíny, s.r.o.'; //FIXME!!!!!
            $this->clientBornDate=$labTestResult->getBornDate();
            $this->accessLogDefaultInfo='id='.$labTestResult->getId().'&legacy_key='.$labTestResult->getLegacyKey();
        } elseif ($class_name=='lwv') { //LABWEB
            $lwv=$labTestResult;
            $this->setDomain($lwv->get_domain());
            $this->xml=$lwv->get_xml('PLAIN_TEXT');
            $this->datdu=new \DateTime($lwv->get_attr('XM_DATDU').'T'.((empty($lwv->get_attr('XM_TIMDU'))) ? '00:00:00' : $lwv->get_attr('XM_TIMDU')));
            $this->hcContractId=$lwv->get_attr('XM_PAROD');
            $co_data=$lwv->get_co_data();
            if (trim($co_data['CO_LEK'])!='') {
                $this->pmATitle=$co_data['CO_TIT'];
                $this->pmName=$co_data['CO_LEK'];
                $this->pmSTitle=$co_data['CO_KAN'];
                $this->pmCompany=$co_data['CO_SPO'];
            } else {
                $this->pmName=$lwv->get_attr('XM_CONAZ');
                $this->pmATitle='';;
                $this->pmSTitle='';
                $this->pmCompany=$lwv->get_attr('XM_CONAZ');
            }
            $this->pmZip=$co_data['CO_PSC'];
            $this->pmAddress=$co_data['CO_ULI'];
            $this->pmCity=$co_data['CO_OBE'];
            $this->pmTel=$co_data['CO_TEL'];
            $this->pmICZ=$co_data['CO_ICZ'];
            $this->pmExpertise=$co_data['CO_ODB'];
            $this->clientBornDate=new \DateTime($lwv->get_attr('XM_PANAR'));
            $this->accessLogDefaultInfo='xm_key='.$lwv->get_attr('XM_KEY');
        } else throw new \Exception('Unsupported data lab test result data source');
        if (preg_match('/\d{5}/', $this->pmZip)) $this->pmZip=substr($this->pmZip, 0, 3).' '.substr($this->pmZip, 3); //formt ZIP into PSC in case of 5 digits format

        $this->dom=new \DOMDocument('1.0', 'utf-8');
        $this->dom->formatOutput=true; 
        $this->dom->preserveWhiteSpace=false;
        $this->dom->loadXML($this->xml);
        $this->xpath=new \DOMXpath($this->dom);

        $this->setIco($this->xpath->query('/dasta/is/@ico')->item(0)->nodeValue ?? '');
        $this->setFileName($this->getDefaultFileName());
        $this->setBaseDate($this->datdu);
    }

    public function getIssuerAddressFromXml() {
       $a=[
          'company'=>($this->xpath->query('/dasta/is/a[@typ="O"]/jmeno')[0]->nodeValue ?? ''),
          'address'=>trim(($this->xpath->query('/dasta/is/a[@typ="O"]/adr')[0]->nodeValue ?? '').', '.($this->xpath->query('/dasta/is/a[@typ="O"]/dop1')[0]->nodeValue ?? '').', '.($this->xpath->query('/dasta/is/a[@typ="O"]/dop2')[0]->nodeValue ?? ''), ', '),
          'city'=>trim(($this->xpath->query('/dasta/is/a[@typ="O"]/psc')[0]->nodeValue ?? '').' '.($this->xpath->query('/dasta/is/a[@typ="O"]/mesto')[0]->nodeValue ?? '')),
          'icl'=>($this->xpath->query('/dasta/is/a[@typ="O"]/icl')->length ? 'IČL '.$this->xpath->query('/dasta/is/a[@typ="O"]/icl')[0]->nodeValue : ''),
       ];
       return $a;
    }

    public function getDefaultFileName() {
        $id_soubor=$this->getDomain()!='CTL' ? $this->xpath->query('/dasta/@id_soubor')[0]->nodeValue : preg_replace('/^STAPRO__OpenLIMS_/', '', $this->xpath->query('/dasta/@id_soubor')[0]->nodeValue);
        $name=trim(iconv('UTF-8', 'US-ASCII//TRANSLIT', $this->xpath->query('/dasta/is/ip/jmeno')[0]->nodeValue ?? '')); //no diacritics!
        $surname=trim(iconv('UTF-8', 'US-ASCII//TRANSLIT', $this->xpath->query('/dasta/is/ip/prijmeni')[0]->nodeValue ?? '')); //no diacritics!
        $dat_du=$this->datdu;
        //preparing PDF version of the XM_XHTML
        $filename=$id_soubor.'_'.$name.'_'.$surname.'_'.($this->hcContractId).'_'.($this->datdu->format('Ymd_Hi')).'.pdf';
        return $this->getFilenameSafeString($filename, false);
    }

    public function getDefaultAccessLogInfo() {
       return $this->accessLogDefaultInfo;
    }

    public function getData(string $format) {
        $primary_materials=[
            'V'=>'V - výpočet',
            'B'=>'B - krev',
            'P'=>'P - plazma (prim. materiál - krev)',
            'S'=>'S - sérum (prim. materiál - krev)',
            'U'=>'U - moč',
            'SW'=>'Stěr', //used for Varapalo
        ];

        $nclp_table=[];
        $vr_extcmnt=[];
        $lclp_comments=[];
        $metd_comments=[];
        $nclp_authors=[];
        $primary_materials_list=[];
        $sampling_date=$received_date=$printed_date=null;
        $sample_id='';

        if ($this->getIco()=='28005902') {
           $primary_materials_list[]=$primary_materials['SW'];
        }

        $nclp_table_i=0;
        $last_lclp=null;
        foreach ($this->xpath->query('/dasta/is/ip/v/vr') as $i=>$vr) {
            if (trim($nclp=$vr->attributes->getNamedItem('klic_nclp')->nodeValue)=='') $nclp='node'.$i;
            $lclp=$vr->getElementsByTagName('nazev_lclp')->length ? trim($vr->getElementsByTagName('nazev_lclp')[0]->nodeValue) : null;

            switch ($nclp) {
            case '46937': //Internal comment, ignored completelly
                $vr->parentNode->removeChild($vr);
                break;

            case '20024': //External comment
                if (($ptext=$this->xpath->query('vrb/text/ptext', $vr))->length) {
                    $vr_extcmnt[]=$ptext->item(0)->nodeValue;
                }
                break;

            ///case '20044': //External comment
            ///if (($ptext=$this->xpath->query('vrr/text/ptext'))->length) {
            ///   $vr_extcmnt[]=$ptext->item(0)->nodeValue;
            ///}
            ///break;
            
            case '48799': //MetdKomment
                if (($ptext=$this->xpath->query('vrb/text/ptext', $vr))->length) {
                    $ptext=$ptext->item(0)->nodeValue;
                    $lines=explode("\n", $ptext);
                    $lclp_detected=null;
                    foreach($lines as $k=>$ln) {
                        $lines[$k]=trim($ln);
                        if (preg_match('/^([A-Z]_\S+):$/', $lines[$k], $r)) {
                           $lclp_detected=$r[1];
                           unset($lines[$k]);
                        }
                    }
                    if (!$lclp_detected && \strlen($lines[0])>0 && \strcasecmp($lclp, 'MetdKoment')===0) {
                        $lclp_detected=trim(array_shift($lines), ':');
                    }
                    if ($lclp_detected===null) $lclp_detected=$last_lclp!==null ? $last_lclp : 'Komentář';

                    if (isset($lclp_comments[$lclp_detected])) {
                        $lclp_comments[$lclp_detected]=array_merge($lclp_comments[$lclp_detected], $lines);
                    } else {
                        if (!isset($metd_comments[$lclp_detected])) $metd_comments[$lclp_detected]=$lines;
                        else $metd_comments[$lclp_detected]==array_merge($metd_comments[$lclp_detected], $lines);$lines;
                     }

                }
                break;
            
            default:
                if ($nclp>='48501' && $nclp<='48800') { //LCLP comments - they may but not must be related to a NCLP (joined by the same LCLP name)
                    if (($ptext=$this->xpath->query('vrb/text/ptext', $vr))->length && $lclp) {
                        $lclp_comments[$lclp][]=$ptext->item(0)->nodeValue;
                    }
                    break;
                }
                if (!$sampling_date && ($dat_du=$vr->getElementsByTagName('dat_du'))->length) $sampling_date=new \DateTime($dat_du->item(0)->nodeValue);
                if (!$received_date && ($dat_pl=$vr->getElementsByTagName('dat_pl'))->length) $received_date=new \DateTime($dat_pl->item(0)->nodeValue);
                if (($dat_vv=$vr->getElementsByTagName('dat_vv'))->length) {
                   $vr_printed_date=new \DateTime($dat_vv->item(0)->nodeValue);
                   if (!$printed_date || $vr_printed_date>$printed_date) $printed_date=$vr_printed_date;
                }
                if (!$sample_id && isset($vr->attributes['id_lis'])) $sample_id=$vr->attributes->getNamedItem('id_lis')->nodeValue;
                if (($author=$vr->getElementsByTagName('autor'))->length && !in_array(trim($author->item(0)->nodeValue), $nclp_authors)) $nclp_authors[]=trim($author->item(0)->nodeValue);
                if ($lclp && preg_match('/^['.implode('', array_keys($primary_materials)).']_/i', $lclp) && isset($primary_materials[$lclp[0]]) && !in_array($primary_materials[$lclp[0]], $primary_materials_list)) $primary_materials_list[]=$primary_materials[$lclp[0]];
                
                if ($vr->getElementsByTagName('vrn')->length) $type='vrn';
                elseif ($vr->getElementsByTagName('vrx')->length) $type='vrx';
                elseif ($vr->getElementsByTagName('vrb')->length) $type='vrb';
                elseif ($vr->getElementsByTagName('vrr')->length) $type='vrr';
                else $type='v__';
                $nclp_table[$nclp_table_i][$nclp][$type]=$vr;
                $nclp_table[$nclp_table_i][$nclp]['lclp']=$lclp;
                $nclp_table_i++;
            }//switch nclp
            $last_lclp=$lclp;
        }

        switch ($format) {
        case 'xml':
            return $this->dom->saveXML();
                
        case 'html': case 'array': case 'json':
            $doc=[
                'client_first_name'=>$this->xpath->query('/dasta/is/ip/jmeno')->item(0)->nodeValue ?? '',
                'client_family_name'=>$this->xpath->query('/dasta/is/ip/prijmeni')->item(0)->nodeValue ?? '',
                'client_full_name'=>trim(($this->xpath->query('/dasta/is/ip/titul_pred')->item(0)->nodeValue ?? '').' '.($this->xpath->query('/dasta/is/ip/jmeno')->item(0)->nodeValue ?? '').' '.($this->xpath->query('/dasta/is/ip/prijmeni')->item(0)->nodeValue ?? '').', '.($this->xpath->query('/dasta/is/ip/titul_za')->item(0)->nodeValue ?? ''), ' ,'),
                'client_pin'=>$this->xpath->query('/dasta/is/ip/rodcis')->item(0)->nodeValue ?? '',
                'client_born_date'=>$this->clientBornDate->format('Y-m-d') ?? '',
                'client_hc_id'=>$this->xpath->query('/dasta/is/ip/p/kodpoj')->item(0)->nodeValue ?? '',
                'client_address'=>$this->xpath->query('/dasta/is/ip/a[@typ="1"]/adr')->item(0)->nodeValue ?? '',
                'client_city'=>$this->xpath->query('/dasta/is/ip/a[@typ="1"]/mesto')->item(0)->nodeValue ?? '',
                'client_zip'=>$this->xpath->query('/dasta/is/ip/a[@typ="1"]/psc')->item(0)->nodeValue ?? '',
                'client_country_code'=>$this->xpath->query('/dasta/is/ip/a[@typ="1"]/stat')->item(0)->nodeValue ?? '',
                'client_tel'=>$this->xpath->query('/dasta/is/ip/a/as[@typ="T"]/obsah')->item(0)->nodeValue ?? '',
                'client_email'=>$this->xpath->query('/dasta/is/ip/a/as[@typ="E"]/obsah')->item(0)->nodeValue ?? '',
                'diag'=>$this->xpath->query('/dasta/is/ip/dg/dgz/diag')->item(0)->nodeValue ?? '',
                'pm_department_text'=>trim($this->pmATitle.' '.$this->pmName.' '.$this->pmSTitle)."\n".trim($this->pmAddress)."\n".trim($this->pmZip.' '.$this->pmCity),
                'pm_tel'=>$this->pmTel,
                'received_date'=>$received_date,
                'printed_date'=>$printed_date,
                'sample_id'=>$sample_id,
                'nclp_table'=>[],
            ];
            if ($format=='html') {
                $doc['client_born_date_dmY']=$this->clientBornDate->format('d/m/Y') ?? '';
                $doc['pm_name']=trim($this->pmATitle.' '.$this->pmName.' '.$this->pmSTitle);
                $doc['pm_company']=$this->pmCompany;
                $doc['pm_address']=trim($this->pmAddress);
                $doc['pm_city']=trim($this->pmZip.' '.$this->pmCity);
                $doc['sampling_date_dmYHi']=$sampling_date->format('d/m/Y H:i');
                $doc['received_date_dmYHi']=$received_date->format('d/m/Y H:i');
                $doc['pm_icz']=$this->pmICZ;
                $doc['pm_expertise']=$this->xpath->query('/dasta/is/ip/lo/zadatel/@odb')->item(0)->nodeValue ?? $this->pmExpertise;
            }
            
            foreach ($nclp_table as $i=>$nclps) {
                foreach ($nclps as $nclp=>$nclp_data) {
                    $lclp=$nclp_data['lclp'];
                    if (isset($nclp_data['vrn'])) {
                        $doc['nclp_table'][$i][$nclp]['title']=$lclp;
                        $doc['nclp_table'][$i][$nclp]['value']=$nclp_data['vrn']->getElementsByTagName('hodnota')->item(0)->nodeValue ?? null;
                        $doc['nclp_table'][$i][$nclp]['unit']=$nclp_data['vrn']->getElementsByTagName('jednotka')->item(0)->nodeValue ?? null;
                        $doc['nclp_table'][$i][$nclp]['s4']=$nclp_data['vrn']->getElementsByTagName('s4')->item(0)->nodeValue ?? null;
                        $doc['nclp_table'][$i][$nclp]['s5']=$nclp_data['vrn']->getElementsByTagName('s5')->item(0)->nodeValue ?? null;
                        $doc['nclp_table'][$i][$nclp]['interpret_g_z']=$nclp_data['vrn']->getElementsByTagName('interpret_g_z')->item(0)->nodeValue ?? null;
                    } elseif (isset($nclp_data['vrx'])) {
                        $doc['nclp_table'][$i][$nclp]['title']=$lclp;
                        $doc['nclp_table'][$i][$nclp]['x-value']=$nclp_data['vrx']->getElementsByTagName('hodnota_nt')->item(0)->nodeValue ?? null;
                    } elseif (isset($nclp_data['vrb'])) {
                        $doc['nclp_table'][$i][$nclp]['title']=$lclp;
                        $doc['nclp_table'][$i][$nclp]['b-value']=$nclp_data['vrb']->getElementsByTagName('ptext')->item(0)->nodeValue ?? null;
                    } elseif (isset($nclp_data['vrr'])) {
                        $doc['nclp_table'][$i][$nclp]['title']=$lclp;
                        $doc['nclp_table'][$i][$nclp]['r-value']=$nclp_data['vrr']->getElementsByTagName('ptext')->length ? preg_replace('/\t/', '   ', $nclp_data['vrr']->getElementsByTagName('ptext')->item(0)->nodeValue) : null;
                    }

                    $comments=[];
                    if (isset($metd_comments[$lclp])) {
                        $comments=array_merge($comments, $metd_comments[$lclp]);
                        unset($metd_comments[$lclp]); //after printing the comment to the related LCLP it has to be removed. At the end of the NCLP table all the rest comments will be printed as a regular NCLP blocks (so called standalone comments)
                    }
                    if (isset($lclp_comments[$lclp])) {
                        $comments=array_merge($comments, $lclp_comments[$lclp]);
                        unset($lclp_comments[$lclp]); //after applying the comment to the related LCLP it has to be removed. At the end of the NCLP table all the rest comments will be printed as a regular NCLP blocks (so called standalone comments)
                    }
                    $nclp_table[$i][$nclp]['comments']=$comments;

                    if (!empty($nclp_table[$i][$nclp]['comments'])) {
                       $doc['nclp_table'][$i][$nclp]['comment']=implode("\n", $nclp_table[$i][$nclp]['comments']);
                    }
                }
            }
            $doc['comments']=$lclp_comments;

            if ($format=='html') {
                $labmetUrl=defined('LABMET_HOST') ? LABMET_HOST : ($_ENV['LABMET_URL'] ?? '');
/*
$co_data=$this->get_co_data();
$pat_name_full=$xml->is->ip->jmeno->__toString().' '.$xml->is->ip->prijmeni->__toString(); //no diacritics!
$pat_pid=$xml->is->ip->rodcis->__toString();
$pat_bd=isset($xml->is->ip->dat_dn) ? (new DateTime($xml->is->ip->dat_dn->__toString()))->format('d.m.Y') : '';
$pat_inscomp_num=isset($xml->is->ip->p->kodpoj) ? $xml->is->ip->p->kodpoj->__toString() : '';
$diag=isset($xml->is->ip->dg) ? $xml->is->ip->dg->dgz->diag->__toString() : '';
//get the full name from wwsan.sql_zhe table, it contains more proper ICZ data than printed into XML

if (!empty($co_data) && trim($co_data['CO_LEK'])!='') {
   //if ($co_data['CO_SPL']) $hc_name_full=$co_data['CO_LEK']; //SPL flag ignored until decision of management
   $hc_name_full=trim($co_data['CO_TIT'].' '.$co_data['CO_LEK'].', '.$co_data['CO_KAN'], ', ');
   $hc_addr_line1=$co_data['CO_SPO'];
} else {
   $hc_name_full=$this->row['XM_CONAZ'];
   $hc_addr_line1='';
}

$hc_addr_line2=isset($xml->pm->a) && isset($xml->pm->a->adr) ? $xml->pm->a->adr->__toString() : $co_data['CO_ULI'];
$hc_addr_line3=isset($xml->pm->a) && isset($xml->pm->a->psc) && isset($xml->pm->a->mesto) ? $xml->pm->a->psc->__toString().' '.$xml->pm->a->mesto->__toString() : $co_data['CO_PSC'].' '.$co_data['CO_OBE'];
$hc_icz=isset($xml->pm->attributes()['icz']) ? $xml->pm->attributes()['icz']->__toString() : '';
$hc_exp=isset($xml->is->ip->lo->zadatel) ? $xml->is->ip->lo->zadatel->attributes()['odb'] : '';

$nclp_table=[];
$vr_extcmnt=[];
$lclp_comments=[];
$nclp_authors=[];
$primary_materials_list=[];
$sampling_date=$received_date=$printed_date=null;
$sample_id='';

foreach ($xml->is->ip->v->vr as $i=>$vr) {
   $nclp=trim($vr->attributes()['klic_nclp']->__toString());
   $lclp=isset($vr->nazev_lclp) ? trim($vr->nazev_lclp->__toString()) : null;
   switch ($nclp) {
   case '46937': //Internal comment, ignored completelly
      break; 
   case '20024': //External comment
      if (isset($vr->vrb->text->ptext)) {
         $vr_extcmnt[]=$vr->vrb->text->ptext->__toString();
      }
      break;
   case '48799': //MetdKomment
      if (isset($vr->vrb->text->ptext)) {
         $text=$vr->vrb->text->ptext->__toString();
         $last_lclp=null;
         foreach (explode("\n", $text) as $ln) {
            $ln=trim($ln);
            if (preg_match('/^([A-Z]_\S+):$/', $ln, $r)) $last_lclp=$r[1];
            elseif ($last_lclp) $lclp_comments[$last_lclp][]=$ln;
         }
      }
      break;
   default:
      if ($nclp>='48501' && $nclp<='48800') { //LCLP comments - they may but not must be related to a NCLP (joined by the same LCLP name)
         if (isset($vr->vrb->text->ptext) && $lclp) {
            $lclp_comments[$lclp][]=$vr;
         }
         break;
      }
      if (!$sampling_date && isset($vr->dat_du)) $sampling_date=new DateTime($vr->dat_du->__toString());
      if (!$received_date && isset($vr->dat_pl)) $received_date=new DateTime($vr->dat_pl->__toString());
      if (isset($vr->dat_vv)) {
         $vr_printed_date=new DateTime($vr->dat_vv->__toString());
         if (!$printed_date || $vr_printed_date>$printed_date) $printed_date=$vr_printed_date;
      }
      if (!$sample_id && isset($vr->attributes()['id_lis'])) $sample_id=$vr->attributes()['id_lis']->__toString();
      if (isset($vr->autor) && !in_array(trim($vr->autor->__toString()), $nclp_authors)) $nclp_authors[]=trim($vr->autor->__toString());
      if ($lclp && preg_match('/^['.implode('',array_keys($primary_materials)).']_/i', $lclp) && isset($primary_materials[$lclp{0}]) && !in_array($primary_materials[$lclp{0}], $primary_materials_list)) $primary_materials_list[]=$primary_materials[$lclp{0}];
      $nclp_section=0;
      if (isset($vr->vrn)) $type='vrn';
      elseif (isset($vr->vrx)) $type='vrx';
      elseif (isset($vr->vrb)) $type='vrb';
      elseif (isset($vr->vrr)) $type='vrr';
      else $type='v__';
      $nclp_table[$nclp_section][$nclp][$type]=$vr;
      $nclp_table[$nclp_section][$nclp]['lclp']=$lclp;
   }//switch
}

$sampling_date=is_object($sampling_date) ? $sampling_date->format('d.m.Y H:i') : '';
$received_date=is_object($received_date) ? $received_date->format('d.m.Y H:i') : '';
$printed_date=is_object($printed_date) ? $printed_date->format('d.m.Y H:i') : '';
*/      
                $result=$this->htmlHeader();
                $result.=<<<EOT
   <body id="page">
      <div class="row"><div class="col-12">
         <table class="layout">
            <tr>
            <td style="border:solid 1px black; vertical-align:top;">
               <i>Klient:</i><br>
               <b>${doc['client_full_name']}</b><br>
               ${doc['client_address']}<br>
               ${doc['client_zip']}&nbsp;${doc['client_city']} ${doc['client_country_code']}<br>
               ${doc['client_tel']}<br>
               <br>
               <table>
                  <tr>
                     <td><i>Číslo pojištěnce:</i></td>
                     <td>${doc['client_pin']}</td>
                  </tr>
                  <tr>
                     <td><i>Datum narození:</i></td>
                     <td>${doc['client_born_date_dmY']}</td>
                  </tr>
                  <tr>
                     <td><i>Pojišťovna:</i>&nbsp;${doc['client_hc_id']}</td>
                     <td><i>Diagnóza:</i>&nbsp;${doc['diag']}</td>
                  </tr>
               </table>
               <br>
            </td>
            <td style="border:solid 1px black; vertical-align:top;">
               <i>Zdravotnické zařízení / lékař:</i><br>
               <b>${doc['pm_name']}</b><br>
               <br>
               ${doc['pm_company']}<br>
               ${doc['pm_address']}<br>
               ${doc['pm_city']}<br>
               <br>
               <table>
                  <tr><td><i>IČZ:</i></td><td>${doc['pm_icz']}</td><td class="pl-5 right"><i>ODB:</i></td><td>${doc['pm_expertise']}</td></tr>
               </table>
            </td>
            </tr>
            <tr>
            <td>
               <table>
                  <tr>
                     <td><i>Datum a čas odběru vzorku:</i></td>
                     <td>${doc['sampling_date_dmYHi']}</td>
                  </tr>
                  <tr>
                     <td><i>Datum a čas přijetí vzorku:</i></td>
                     <td>${doc['received_date_dmYHi']}</td>
                  </tr>
               </table>
            </td>
            <td>
               <table>
                  <tr>
                     <td><i>Materiál číslo:</i></td>
                     <td><b>${doc['sample_id']}</b></td>
                  </tr>
               </table>
            </td>
            </tr>
            </tbody>
         </table>
      </div></div>

      <div class="row"><div class="col-12">
EOT;
               $result.='<table class="nclptable"><thead><tr><th width="40%">Mat | Název vyšetření</th><th class="center" width="10%">Výsledek</th><th class="center" width="10%">Jednotky</th><th class="center" width="20%">Ref. interval</th><th class="center" width="20%">Hodnocení</th></tr></thead>'.PHP_EOL;
               $result.='<tbody>'.PHP_EOL;
               $first_section=true;
               foreach ($nclp_table as $section=>$nclps) {
                  //if ($first_section && $section!==0) $result.='<tr class="section"><td colspan=5><b>'.($section!==0 ? $section : '').'</b></td></tr>';
                  foreach ($nclps as $nclp=>$nclp_data) {
                     $lclp=$nclp_data['lclp'];
                     if (isset($nclp_data['vrn'])) {
                        $vr=$nclp_data['vrn'];
                        $result.='<tr><td class="data left" width="40%"><a href="'.$labmetUrl.'/'.$this->getDomain().'/'.$nclp.'">'.$lclp.'</a></td>'.
                           '<td class="data right" width="10%">'.($nclp_data['vrn']->getElementsByTagName('hodnota')->item(0)->nodeValue ?? '').'</td>'.
                           '<td class="data left" width="10%">'.($nclp_data['vrn']->getElementsByTagName('jednotka')->item(0)->nodeValue ?? '').'</td>'.
                           '<td class="data center" width="20%">'.($nclp_data['vrn']->getElementsByTagName('s4')->item(0)->nodeValue ?? '').' - '.($nclp_data['vrn']->getElementsByTagName('s5')->item(0)->nodeValue ?? '').'</td>'.
                           '<td class="data center" width="20%">'.($nclp_data['vrn']->getElementsByTagName('interpret_g_z')->item(0)->nodeValue ?? '').'</td>'.
                           '</tr>'.PHP_EOL;
                     } elseif (isset($nclp_data['vrx'])) {
                        $result.='<tr><td class="data left" width="40%"><a href="'.$labmetUrl.'/'.$this->getDomain().'/'.$nclp.'">'.$lclp.'</a></td>'.
                           '<td class="data left" colspan="4" width="60%">'.(trim($nclp_data['vrx']->getElementsByTagName('hodnota_nt')->item(0)->nodeValue ?? '')).'</td>'.
                           '</tr>'.PHP_EOL;
                     } elseif (isset($nclp_data['vrb'])) {
                        $result.='<tr><td class="data left" width="40%"><a href="'.$labmetUrl.'/'.$this->getDomain().'/'.$nclp.'">'.$lclp.'</a></td><td class="data" colspan="4" width="60%"><p class="ptext italic">'.nl2br(trim(\htmlspecialchars($nclp_data['vrb']->getElementsByTagName('ptext')->item(0)->nodeValue ?? ''))).'</p></td></tr>'.PHP_EOL;
                     } elseif (isset($nclp_data['vrr'])) {
                        $result.='<tr><td class="data left" width="40%"><a href="'.$labmetUrl.'/'.$this->getDomain().'/'.$nclp.'">'.$lclp.'</a></td><td class="data" colspan="4" width="60%"><p class="ptext italic">'.nl2br(trim(preg_replace('/\t/', '   ', \htmlspecialchars(($nclp_data['vrr']->getElementsByTagName('ptext')->item(0)->nodeValue ?? ''))))).'</p></td></tr>'.PHP_EOL;
                     }
                     if (!empty($nclp_data['comments'])) {
                        $result.='<tr><td class="comment left" colspan="5" width="100%"><p class="ptext italic">'.nl2br(trim(htmlspecialchars(implode("\n", $nclp_data['comments'])))).'</p></td></tr>'.PHP_EOL;
                     }
                  }      
               }
               //print all standalone NCLP comments
               foreach ($lclp_comments as $lclp=>$vr) {
                  if (!is_object($vr)) continue; //unused simple comments cannot be used as standalone comments - they stay not printed!
                  $result.='<tr><td class="data left" width="40%"><a href="'.$labmetUrl.'/'.$this->getDomain().'/'.$nclp.'">'.trim($vr->nazev_lclp->__toString()).'</a></td><td class="data" colspan="4" width="60%"><p class="ptext italic">'.trim($vr->vrb->text->ptext->__toString()).'</p></td></tr>'.PHP_EOL;
               }
               $result.='</tbody></table></div></div>'.PHP_EOL;

               //nclp table footer
               $result.='<div class="row legend"><div class="col-12" style="border:solid 1px black">'.PHP_EOL;
               $result.='<table class="layout">'.PHP_EOL;
               $result.='   <tr><td class="italic" style="width:20%">Uvolnil:</td><td>'.implode(', ', $nclp_authors).'</td></tr>'.PHP_EOL;
               if (!empty($vr_extcmnt)) {
                  $result.='   <tr><td class="italic" style="width:20%">Doplňující údaje / komentář:</td><td>'.implode("\n", $vr_extcmnt).'</td></tr>'.PHP_EOL;
               }
               $result.='   <tr><td class="italic" style="width:20%">Datum a čas tisku:</td><td>'.($doc['printed_date'] ? $doc['printed_date']->format('d/m/Y H:i:s'): '').'</td></tr>'.PHP_EOL;
               $result.='   <tr><td class="italic comment" style="width:20%">Primární materiál:</td><td class="comment">'.implode(', ', $primary_materials_list).'</td></tr>'.PHP_EOL;
               $result.='</table>'.PHP_EOL;

               $result.='</div><!-- .col-12 -->'.PHP_EOL;
               $result.='</div><!-- .row -->'.PHP_EOL;

               $result.=$this->htmlInfoTip('Názvy jednotlivých vyšetření jsou hypertextové odkazy, po jejichž rozkliknutí získáte doplňující informace k vyšetření, především pak v rozsahu jejich indikace a interpretace.');

               $result.='</body>'.PHP_EOL;
               $result.=$this->htmlFooter();
               return $result;
            }

            if ($format=='json') {
               return json_encode($doc);
            }

            //Array format
            return $doc;

        }//switch
    }

    public function htmlHeader() {
      $result=<<<EOT
<!DOCTYPE html>
<html lang="cs">
   <head>
      <meta charset="UTF-8">
      <meta http-equiv="Content-language" content="cs">
      <title>Výsledek laboratorního vyšetření</title>
      <meta name="autor" content="Lab In - Institut laboratorní medicíny">
      <style type="text/css">
* {
 box-sizing:border-box;
}
body {
   font-family: "DejaVu Sans"; /*because of ua characters!*/
   font-size: 10pt;
   background: #fff !important;
   color: #000;
}
#page {
   width: 100%; 
   margin: 0; 
   float: none;
}

@page :top {
   margin: 0.5cm;
}
@page :bottom {
   margin: 0cm;
}
@page :left {
   margin: 1cm;
}
@page :right {
   margin: 1cm;
}
.legend {
   page-break-inside : avoid;
}
.row::after, .arow::after {
  content: "";
  clear: both;
  display: table;
}

[class*="col-"] {
  float: left;
  padding: 0px 0px 0px 0px;
  text-align:left;
  overflow:hidden;
}
p { margin: 0;  padding: 0;  }
.mb-1 {
   margin-bottom:0.5em;
}
.mb-2 {
   margin-bottom:1em;
}
.mb-3 {
   margin-bottom:2em;
}
.mt-2 {
   margin-top:1em;
}
.mt-3 {
   margin-top:2em;
}
.mt-4 {
   margin-top:3em;
}
.mt-5 {
   margin-top:5em;
}
.mr-1 {
   margin-right:1em;
}
.ml-1 {
   margin-left:1em;
}
.pt-1 {
   padding-top:1em;
}
.pt-2 {
   padding-top:1.5em;
}
.pl-1 {
   padding-left:1em;
}
.pl-2 {
   padding-left:2em;
}
.pl-3 {
   padding-left:3em;
}
.pl-5 {
   padding-left:8em;
}
.pr-1 {
   padding-right:1em;
}
.pr-3 {
   padding-right:8em;
}

.lg {
   width:12em;
}

em {
   font-weight:bolder;
   font-style:normal;
}
h1 {
   font-size:1.1em;
   padding:0;
   margin:0;
}
.bold {
   font-weight:bold;
}
.center {
   text-align:center;
}
.left {
   text-align:left;
}
.right {
   text-align:right;
}
.inline {
   display:inline;
}
.inline-block {
   display:inline-block;
}
.input {
  border-bottom:dotted 1px black;
  padding:0 0 0 1em;
}
.border {
   border:solid 1px black;
}
/* For mobile phones: */
[class*="col-"] {
  width: 100%;
}
/* For desktop: */
.col-0 {width: 0%;}
.col-1 {width: 8.33%;}
.col-2 {width: 16.66%;}
.col-3 {width: 25%;}
.col-4 {width: 33.33%;}
.col-5 {width: 41.66%;}
.col-6 {width: 50%;}
.col-7 {width: 58.33%;}
.col-8 {width: 66.66%;}
.col-9 {width: 75%;}
.col-10 {width: 83.33%;}
.col-11 {width: 91.66%;}
.col-12 {width: 100%;}
.col-13 {width: 29.16%;} /*|col-5|+|col-13|+|col-13|=100%*/
a#odhlasit_service { display:none; }

a {
   text-decoration: none;
}
.d-none { display:none }
p.result { background-color:#e0e0e0 }

@media only screen {
   p.positive { background-color:#ea9999 }
   p.negative { background-color:#1CF250 }
}
@media only print {
   .noprint { display:none }
}
table.nclptable {
   width:100%;
   border-collapse:collapse;
}
table.nclptable td.data {
   border-top:dotted 0.1pt #c0c0c0;
   vertical-align:top;
   word-wrap: break-word;
}
table.nclptable td.comment {
   font-size:1.0em;
   vertical-align:top;
}
table.nclptable th {
   font-size:0.9em;
   border:solid 1px black;
}
table.layout {
   width:100%;
   border-collapse:collapse;
   font-size:0.9em;
}
.ptext {
   word-wrap: break-word;
   white-space: pre-wrap;
   font-size:0.9em;
   overflow-wrap: break-word;
   word-break: break-all;
}
.italic {
   font-style:italic;
}
.tipbox {
   font-style: italic;
   color:#606060;
}
      </style>
   </head>
EOT;
      return $result;
    }

    public function htmlFooter() {
        return '</html>'.PHP_EOL;
    }

}

