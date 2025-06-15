<?php

namespace rsvitak\labdoc;

class LabTestResultDoc {
    private $domain;
    private $fileName;
    private $xml;
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
        if (is_object($labTestResult) && get_class($labTestResult)=='LabTestResult') { //LABIS
            $this->fileName=LabUtils::getLabTestResultFilename($labTestResult);
            $this->setDomain($labTestResult->getDomain());
            $this->xml=$labTestResult->getXml();
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
        } elseif (is_object($labTestResult) && get_class($labTestResult)=='lwv') { //LABWEB
            $lwv=$labTestResult;
            $this->fileName=$lwv->get_custom_filename();
            $this->setDomain($lwv->get_domain());
            $this->xml=$lwv->get_xml('PLAIN_TEXT');
            $co_data=$lwv->get_co_data();
            if (trim($co_data['CO_LEK'])!='') {
                $this->pmATitle=$co_data['CO_TIT'];
                $this->pmName=$co_data['CO_LEK'];
                $this->pmSTitle=$co_data['CO_KAN'];
                $this->pmCompany=$co_data['CO_SPO'];
            } else {
                $hc_name_full=$labTestResult['XM_CONAZ'];
                $hc_addr_line1='';
            }
            $this->pmZip=$co_data['CO_PSC'];
            $this->pmAddress=$co_data['CO_ULI'];
            $this->pmCity=$co_data['CO_OBE'];
            $this->pmTel=$co_data['CO_TEL'];
            $this->pmICZ=$co_data['CO_ICZ'];
            $this->pmExpertise=$co_data['CO_ODB'];
        }
        if (preg_match('/\d{5}/', $this->pmZip)) $this->pmZip=substr($this->pmZip, 0, 3).' '.substr($this->pmZip, 3); //formt ZIP into PSC in case of 5 digits format
    }

    public function getDomain() {
        return $this->domain;
    }

    public function setDomain($domain) {
        $this->domain=$domain;
        return $this;
    }

    public function getOutput($format, $cachedFileName=true) {
        $format=strtolower(trim($format));

        if ($format=='pdf' && $cachedFile!==false) {
            if (($cachedPdfData=(new LabPdf())->setFileName($cachedFileName)->getOutput())) {
                return $cachedPdfData;
            }
        }

        $primary_materials=[
            'V'=>'V - výpočet',
            'B'=>'B - krev',
            'P'=>'P - plazma (prim. materiál - krev)',
            'S'=>'S - sérum (prim. materiál - krev)',
            'U'=>'U - moč',
        ];

        $nclp_table=[];
        $vr_extcmnt=[];
        $lclp_comments=[];
        $metd_comments=[];
        $nclp_authors=[];
        $primary_materials_list=[];
        $sampling_date=$received_date=$printed_date=null;
        $sample_id='';

        $dom=new \DOMDocument('1.0', 'utf-8');
        $dom->formatOutput=true; 
        $dom->preserveWhiteSpace=false;
        $dom->loadXML($this->xml);
        $xpath=new \DOMXpath($dom);

        $nclp_table_i=0;
        foreach ($xpath->query('/dasta/is/ip/v/vr') as $i=>$vr) {
            if (trim($nclp=$vr->attributes->getNamedItem('klic_nclp')->nodeValue)=='') $nclp='node'.$i;
            $lclp=$vr->getElementsByTagName('nazev_lclp')->length ? trim($vr->getElementsByTagName('nazev_lclp')[0]->nodeValue) : null;
            switch ($nclp) {
            case '46937': //Internal comment, ignored completelly
                $vr->parentNode->removeChild($vr);
                break;

            case '20024': //External comment
                if (($ptext=$xpath->query('vrb/text/ptext', $vr))->length) {
                    $vr_extcmnt[]=$ptext->item(0)->nodeValue;
                }
                break;

            ///case '20044': //External comment
            ///if (($ptext=$xpath->query('vrr/text/ptext'))->length) {
            ///   $vr_extcmnt[]=$ptext->item(0)->nodeValue;
            ///}
            ///break;
            
            case '48799': //MetdKomment
                if (($ptext=$xpath->query('vrb/text/ptext', $vr))->length) {
                    $ptext=$ptext->item(0)->nodeValue;
                    $last_lclp=null;
                    foreach (explode("\n", $ptext) as $ln) {
                        $ln=trim($ln);
                        if (preg_match('/^([A-Z]_\S+):$/', $ln, $r)) $last_lclp=$r[1];
                        elseif ($last_lclp) $metd_comments[$last_lclp][]=$ln;
                    }
                }
                break;
            
            default:
                if ($nclp>='48501' && $nclp<='48800') { //LCLP comments - they may but not must be related to a NCLP (joined by the same LCLP name)
                    if (($ptext=$xpath->query('vrb/text/ptext', $vr))->length && $lclp) {
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
        }

        switch ($format) {
        case 'xml':
            return $dom->saveXML();
                
        case 'pdf': case 'array':
            $doc=[
                'client_first_name'=>$xpath->query('/dasta/is/ip/jmeno')->item(0)->nodeValue ?? '',
                'client_family_name'=>$xpath->query('/dasta/is/ip/prijmeni')->item(0)->nodeValue ?? '',
                'client_full_name'=>trim(($xpath->query('/dasta/is/ip/titul_pred')->item(0)->nodeValue ?? '').' '.($xpath->query('/dasta/is/ip/jmeno')->item(0)->nodeValue ?? '').' '.($xpath->query('/dasta/is/ip/prijmeni')->item(0)->nodeValue ?? '').', '.($xpath->query('/dasta/is/ip/titul_za')->item(0)->nodeValue ?? ''), ' ,'),
                'client_pin'=>$xpath->query('/dasta/is/ip/rodcis')->item(0)->nodeValue ?? '',
                'client_born_date'=>$labTestResult->getBornDate()->format('Y-m-d') ?? '',
                'client_hc_id'=>$xpath->query('/dasta/is/ip/p/kodpoj')->item(0)->nodeValue ?? '',
                'diag'=>$xpath->query('/dasta/is/ip/dg/dgz/diag')->item(0)->nodeValue ?? '',
                'pm_department_text'=>trim($this->pmATitle.' '.$this->pmName.' '.$this->pmSTitle)."\n".trim($this->pmAddress)."\n".trim($this->pmZip.' '.$this->pmCity),
                'pm_tel'=>$this->pmTel,
                'received_date'=>$received_date,
                'printed_date'=>$printed_date,
                'sample_id'=>$sample_id,
                'nclp_table'=>[],
            ];
            if ($format=='pdf') {
                $doc['client_born_date_dmY']=$labTestResult->getBornDate()->format('d/m/Y') ?? '';
                $doc['pm_name']=trim($this->pmATitle.' '.$this->pmName.' '.$this->pmSTitle);
                $doc['pm_company']=$this->pmCompany;
                $doc['pm_address']=trim($this->pmAddress);
                $doc['pm_city']=trim($this->pmZip.' '.$this->pmCity);
                $doc['sampling_date_dmYHi']=$sampling_date->format('d/m/Y H:i');
                $doc['received_date_dmYHi']=$received_date->format('d/m/Y H:i');
                $doc['pm_icz']=$this->pmICZ;
                $doc['pm_expertise']=$xpath->query('/dasta/is/ip/lo/zadatel/@odb')->item(0)->nodeValue ?? $this->pmExpertise;
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
                    if (isset($metd_comments[$lclp])) {
                        $doc['nclp_table'][$i][$nclp]['comment']=implode("\n", $metd_comments[$lclp]);
                    }
                    if (isset($lclp_comments[$lclp])) {
                        $doc['nclp_table'][$i][$nclp]['comment']=implode("\n", $lclp_comments[$lclp]);
                        unset($lclp_comments[$lclp]); //after printing the comment to the related LCLP it has to be removed. At the end of the NCLP table all the rest comments will be printed as a regular NCLP blocks (so called standalone comments)
                    }
                }
            }
            $doc['nclp_comments']=$lclp_comments;

            if ($format=='pdf') {
                $labmetUrl=defined('LABMET_URL') ? LABMET_URL : ($_ENV['LABMET_URL'] ?? '');
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
      $result=<<<EOT
<!DOCTYPE html>
<html lang="cs">
   <head>
      <meta charset="UTF-8">
      <meta http-equiv="Content-language" content="cs">
      <title>Výsledek laboratorního vyšetření</title>
      <meta name="autor">Lab In - Institut laboratorní medicíny</meta>
      <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
      <style type="text/css">
* {
 box-sizing:border-box;
}
body {
   font-family: "DejaVu Sans"; /*because of ua characters!*/
   font-size: 8pt;
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
#content {
   padding-left:2%; 
   padding-right:2%; 
   margin: 0; 
   float: none;
}

/*
#page {
   page-break-inside : avoid;
}
*/
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
.comment {
   font-size:0.8em;
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

.d-none { display:none }
p.result { background-color:#e0e0e0 }
@media only screen {
   p.positive { background-color:#ea9999 }
   p.negative { background-color:#1CF250 }
}
table.docheader {
   width:100%;
   font-size:1.0em;
}
table.nclptable {
   width:100%;
   border-collapse:collapse;
}
table.nclptable td {
   border-bottom:dotted 0.1pt #a0a0a0;
}
table.nclptable th {
   font-size:0.9em;
   border:solid 1px black;
}
table.nclptable tbody tr:first-child td {
   padding-top:0.5em;
}
table.nclptable tbody tr:last-child td {
   padding-bottom:0.9em;
   border:none;
}
table.nclptable td {
   font-size:1.0em;
   padding:0em 0.5em 0.1em 0.5em;
}
table.nclptable tr.section td {
   padding:0.5em 0 0 0;
}
table.nclptable-footer {
   width:100%;
   font-size:0.9em;
}
table.nclptable-footer td {
   border-style:none;
}
table.nclptable-footer {
   border-top:solid 1px black;
   border-bottom:solid 1px black;
}
table.nclptable-footer td:first-child {
   width:18%;
}
.ptext {
   white-space: pre-wrap;
   font-size:0.9em;
}
.italic {
   font-style:italic;
}
      </style>
   </head>
   <body id="page">
      <div class="row"><div class="col-12">
         <table class="docheader">
            <tr>
            <td style="border:solid 1px black; padding:0.5em; vertical-align:top">
               <i>Klient:</i><br>
               <b>${doc['client_full_name']}</b><br><br>
               <table>
                  <tr>
                     <td><i>Číslo pojištěnce:</i></td>
                     <td>${doc['client_pin']}</td>
                     <td></td>
                     <td></td>
                  </tr>
                  <tr>
                     <td><i>Datum narození:</i></td>
                     <td>${doc['client_born_date_dmY']}</td>
                     <td></td>
                     <td></td>
                  </tr>
                  <tr>
                     <td><i>Pojišťovna:</i></td>
                     <td>${doc['client_hc_id']}</td>
                     <td class="right"><i>Diagnóza:</i></td>
                     <td>${doc['diag']}</td>
                  </tr>
               </table>
            </td>
            <td style="border:solid 1px black; padding:0.5em; vertical-align:top">
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
            <td style="padding:0.5em; vertical-align:top">
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
            <td style="padding:0.5em; vertical-align:top">
               <table>
                  <tr>
                     <td><i>Materiál číslo:</i></td>
                     <td><b>${doc['sample_id']}</b></td>
                  </tr>
               </table>
            </td>
            </tr>
         </table>
      </div></div>
      <div class="row"><div class="col-12">
EOT;
      $result.='<table class="nclptable"><thead><tr><th>Mat + Název vyšetření</th><th class="center">Výsledek</th><th class="center">Jednotky</th><th class="center">Ref. interval</th><th class="center">Hodnocení</th></tr></thead>';
      $result.='<tbody>';
      $first_section=true;
      foreach ($nclp_table as $section=>$nclps) {
         //if ($first_section && $section!==0) $result.='<tr class="section"><td colspan=5><b>'.($section!==0 ? $section : '').'</b></td></tr>';
         foreach ($nclps as $nclp=>$nclp_data) {
            $lclp=$nclp_data['lclp'];
            if (isset($nclp_data['vrn'])) {
               $vr=$nclp_data['vrn'];
               $result.='<tr><td class="left"><a href="'.$labmetUrl.'/'.$this->getDomain().'/'.$nclp.'">'.$lclp.'</a></td>'.
                  '<td class="data right">'.($nclp_data['vrn']->getElementsByTagName('hodnota')->item(0)->nodeValue ?? '').'</td>'.
                  '<td class="left">'.($nclp_data['vrn']->getElementsByTagName('jednotka')->item(0)->nodeValue ?? '').'</td>'.
                  '<td class="center">'.($nclp_data['vrn']->getElementsByTagName('s4')->item(0)->nodeValue ?? '').' - '.($nclp_data['vrn']->getElementsByTagName('s5')->item(0)->nodeValue ?? '').'</td>'.
                  '<td class="center">'.($nclp_data['vrn']->getElementsByTagName('interpret_g_z')->item(0)->nodeValue ?? '').'</td>'.
                  '</tr>';
            } elseif (isset($nclp_data['vrx'])) {
               $result.='<tr><td class="left"><a href="'.$labmetUrl.'/'.$this->getDomain().'/'.$nclp.'">'.$lclp.'</a></td>'.
                  '<td class="data left" colspan="4">'.($nclp_data['vrx']->getElementsByTagName('hodnota_nt')->item(0)->nodeValue ?? '').'</td>'.
                  '</tr>';
            } elseif (isset($nclp_data['vrb'])) {
               $result.='<tr><td class="left"><a href="'.$labmetUrl.'/'.$this->getDomain().'/'.$nclp.'">'.$lclp.'</a></td><td colspan="4"><p class="ptext italic">'.nl2br($nclp_data['vrb']->getElementsByTagName('ptext')->item(0)->nodeValue ?? '').'</p></td></tr>';
            } elseif (isset($nclp_data['vrr'])) {
               $result.='<tr><td class="left"><a href="'.$labmetUrl.'/'.$this->getDomain().'/'.$nclp.'">'.$lclp.'</a></td><td colspan="4"><p class="ptext italic">'.nl2br(preg_replace('/\t/', '   ', \htmlspecialchars(($nclp_data['vrr']->getElementsByTagName('ptext')->item(0)->nodeValue ?? '')))).'</p></td></tr>';
            }
            if (isset($lclp_comments[$lclp])) {
               $result.='<tr><td colspan="5"><p class="ptext italic">';
               $text=[];
               foreach ($lclp_comments[$lclp] as $comment) {
                  if (is_string($comment)) $text[]=$comment;
                  else $text[]=$comment->vrb->text->ptext->__toString();
               }
               $result.=nl2br(implode("\n", $text)).'</p></td></tr>';
               unset($lclp_comments[$lclp]); //after printing the comment to the related LCLP it has to be removed. At the end of the NCLP table all the rest comments will be printed as a regular NCLP blocks (so called standalone comments)
            }
         }      
      }
      //print all standalone NCLP comments
      foreach ($lclp_comments as $lclp=>$vr) {
         if (!is_object($vr)) continue; //unused simple comments cannot be used as standalone comments - they stay not printed!
         $result.='<tr><td class="left"><a href="'.$labmetUrl.'/'.$this->getDomain().'/'.$nclp.'">'.trim($vr->nazev_lclp->__toString()).'</a></td><td colspan="4"><p class="ptext italic">'.$vr->vrb->text->ptext->__toString().'</p></td></tr>';
      }
      $result.='</tbody></table></div></div>';

      //nclp table footer
      $result.='<div class="row"><div class="col-12">';
      $result.='<table class="nclptable-footer">';
      $result.='   <tr><td class="italic">Uvolnil:</td><td>'.implode(', ', $nclp_authors).'</td></tr>';
      if (!empty($vr_extcmnt)) {
         $result.='   <tr><td class="italic">Doplňující údaje / komentář:</td><td>'.implode("\n", $vr_extcmnt).'</td></tr>';
      }
      $result.='   <tr><td class="italic">Datum a čas tisku:</td><td>'.$doc['printed_date']->format('d/m/Y H:i:s').'</td></tr>';
      $result.='   <tr><td class="italic comment">Primární materiál:</td><td class="comment">'.implode(', ', $primary_materials_list).'</td></tr>';
      $result.='</table>';
      $result.=<<<EOT
</div><!-- .col-12 --></div><!-- .row -->
</body></html>
EOT;
                $labPdf=(new LabPdf())
                    ->setDomain($this->domain)
                    ->setTitle('Výsledky laboratorního vyšetření')
                    ->setSubject('')
                    ->setFileName($this->fileName)
                    ->loadHtml($result)
                ;
                return $labPdf->getOutput();
            }

            //Array format
            return $doc;
        }//switch
    }
}

