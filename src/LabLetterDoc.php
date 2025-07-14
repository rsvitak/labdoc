<?php
use rsvitak\labdoc\LabDoc;

namespace rsvitak\labdoc;

class LabLetterDoc extends LabDoc {
   private $body;
   private $fileName;

    public function __construct($domain, $subject, $body, $ico='') {
       $this->setDomain($domain);
       $this->setTitle($subject);
       $this->body=$body;
       $this->setBaseDate(new \DateTime());
       $this->setIco($ico);
       $this->setFileName($this->getDefaultFileName());
    }
    
    public function getDefaultFileName() {
        //write PDF - per DO_ID
        $filenameSegments=[
           $this->getTitle(),
           $this->getDomain(),
           $this->getBaseDate()->format('Ymd\THis'),
           strlen($this->body)
        ];
        return $this->getFilenameSafeString(implode('_', $filenameSegments).'.pdf', false);
    }

    public function getDefaultAccessLogInfo() {
       return '';
    }

    public function getData(string $format) {
        if ($format!='html') throw new Exception('Unsupported LabLetterDoc data format '.$format);
        $result=$this->htmlHeader().PHP_EOL.'<body id="page">'.PHP_EOL.$this->body.PHP_EOL.'</body>';
        return $result;
    }

    public function htmlHeader() {
      $result=<<<EOT
<!DOCTYPE html>
<html lang="cs">
   <head>
      <meta charset="UTF-8">
      <meta http-equiv="Content-language" content="cs">
      <title>Žádanka na laboratorní vyšetření</title>
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
#page, .app-box {
   page-break-inside : avoid;
   border-top:dotted 1px gray;
}
#pageseparator, .pageseparator	{
	page-break-before: always;
/*   border-top:dashed 1px #a0a0a0;*/
}
.row::after, .arow::after {
  content: "";
  clear: both;
  display: table;
}
.highlighted-value {
   font-weight:bold;
}
[class*="col-"] {
  float: left;
  padding: 0px 0px 0px 0px;
  text-align:left;
  overflow:hidden;
}
p, div { margin: 0;  padding: 0;  }
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
h1,h2,h3,h4,h5 {
   padding:0;
   margin:0;
}
h1 {
   font-size:1.1em;
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
@media only print {
   .noprint { display:none }
}
table.nclptable {
   width:100%;
   border-collapse:collapse;
}
table.nclptable td.data {
   border-bottom:dotted 0.1pt #c0c0c0;
   vertical-align:top;
}
table.nclptable th {
   font-size:0.9em;
   border:solid 1px black;
}
table[class^="zadanka-print"] {
   width:100%;
   border-collapse:collapse;
   font-size:0.9em;
}
table.ltrheader tr td {
   padding-top: 20px;
}
.italic {
   font-style:italic;
}
.tipbox {
	background-color: #cfe2ff;
	border: 1px solid #b6d4fe;
   border-radius:5px;
   color:#084298;
	padding: 5px;
   /*margin: 5px;*/
	font-style: italic;
}

.badge {
   display: inline-block;
   padding: .25em .4em;
   font-size: 75%;
   font-weight: 700;
   line-height: 1;
   text-align: center;
   white-space: nowrap;
   vertical-align: baseline;
   border-radius: .25rem;
}
.badge-danger {
   color: #fff;
   background-color: #dc3545;
}
.badge-primary {
   color: #fff;
   background-color: #007bff;
}
.badge-success {
   color: #fff;
   background-color: #28a745;
}
.badge-secondary {
   color: #fff;
   background-color: #6c757d;
}
.badge-warning {
   color: #000;
   background-color: #ffc107;
}
.badge-info {
   color: #fff;
   background-color: #17a2b8;
}
.badge-light {
   color: #212529;
   background-color: #f8f9fa;;
}
.badge-dark {
   color: #fff;
   background-color: #343a40;
}
.badge-sm { font-size:50%;}
.badge-xl { font-size:150%;}
.badge-xxl { font-size:200%;}

table.simpletable {
   width:100%;
   border-collapse:collapse;
   border:solid 1px #606060;
}
table.simpletable th {
   background-color:#a0a0a0;
   font-weight:bold;
   border:solid 1px #606060;
}
table.simpletable td {
   font-weight:normal;
   font-size:0.9em;
   border:solid 1px #606060;
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
