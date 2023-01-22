<?php

declare(strict_types=1);

namespace Glued\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use League\Csv\Reader;

class ServiceController extends AbstractController
{

    public function health(Request $request, Response $response, array $args = []): Response {

    }

    public function dl_r1(Request $request, Response $response, array $args = []): Response {
        $rp = $request->getQueryParams();

        if (!array_key_exists('reg', $rp)) {
            throw new \Exception('A reg parameter must be provided. Allowed values are mfcr-ares, czso-res, mojedatovaschranka,',400);
        }
        if ($rp['reg'] == 'mfcr-ares') {
            $uri[] = 'https://wwwinfo.mfcr.cz/ares/ares_vreo_all.tar.gz';
        } elseif ($rp['reg'] == 'czso-res') {
            $uri[] = 'https://opendata.czso.cz/data/od_org03/res_data.csv';
            $uri[] = 'https://opendata.czso.cz/data/od_org03/res_data-metadata.json';
        } elseif ($rp['reg'] == 'mojedatovaschranka') {
            $uri[] = 'https://www.mojedatovaschranka.cz/sds/datafile?format=xml&service=seznam_ds_po';
            $uri[] = 'https://www.mojedatovaschranka.cz/sds/datafile?format=xml&service=seznam_ds_pfo';
            $uri[] = 'https://www.mojedatovaschranka.cz/sds/datafile?format=xml&service=seznam_ds_fo';
            $uri[] = 'https://www.mojedatovaschranka.cz/sds/datafile?format=xml&service=seznam_ds_ovm';
        } else {
            throw new \Exception('Bad reg parameter provided. Allowed values are mfcr-ares, czso-res, mojedatovaschranka,',400);
        }

        ini_set('memory_limit', '768M');
        ini_set("default_socket_timeout", 600);
        ini_set('max_execution_time', 600);

        foreach ($uri as $u) {
            $res = $this->guzzle->request('GET', $u, [
                'sink' => $this->settings['glued']['datapath'] . '/' . $this->settings['glued']['uservice'] . '/data/'.basename($u),
                'defaults' => [
                    'stream' => true,
                    //'read_timeout' => 600,
                    //'timeout' => 10.0,
                ]
            ]);
        }
        return $response->withJson(['message' => 'Download complete.']);

    }

    // TODO reimplement https://github.com/vaizard/glued-skeleton-modular/tree/master/glued/Contacts/Classes
    // TODO get accounts and reliable tax payher status https://adisspr.mfcr.cz/pmd/dokumentace/webove-sluzby-spolehlivost-platcus

    public function parse_r1(Request $request, Response $response, array $args = []): Response
    {
        ini_set("fastcgi_read_timeout", "300");
        ini_set("max_execution_time", "300");

        echo ini_get("fastcgi_read_timeout");
        //load the CSV document from a file path
        $csv = Reader::createFromPath('/var/www/html/data/glued-integrations-czregs/data/res_data.csv', 'r');
        $csv->setHeaderOffset(0);

        $header = $csv->getHeader();
        var_dump($header);

        $rows = $csv->getRecords();
        foreach ($rows as $r) {
            $d['regid'] = $r[0];            // 0  ICO       ico
            $d['date']['birth'] = $r[2];     // 2  DDATVZN   vznik
            $d['date']['death'] = $r[3];     // 3  DDATZAN   zanik
            $d['date']['foundation'] = $r[2];  // 2  DDATVZN   vznik
            $d['date']['dissolution'] = $r[3]; // 3  DDATZAN   zanik
            $d['_iat'] = $r[5];               // 5  DDATPAKT  aktualizace
            $d['name'] = $r[11];              // 11 FIRMA
            $d['a']['value'] = $r[14];        // 14 TEXTADR
            $d['a']['zip'] = $r[15];          // 15 PSC
            $d['a']['municipality'] = $r[16]; // 16 OBEC_TEXT
            $d['a']['quarter'] = $r[17];      // 17 COBCE_TEXT
            $d['a']['street'] = $r[18];       // 18 ULICE_TEXT
            $d['a']['conscr_nr'] = $r[20];    // 20 CDOM
            $d['a']['street_nr'] = $r[21];    // 21 COR
            $d['_rat'] = $r[22];    // 22 DATPLAT
        }

        //$header = $csv->getHeader(); //returns the CSV header record
        //$records = $csv->getRecords(); //returns all the CSV records as an Iterator object

        //echo $csv->toString(); //returns the CSV document as a string
        return $response;
    }

    public function ares_r1(Request $request, Response $response, array $args = []): Response {
        $rp = $request->getQueryParams();
        if (!array_key_exists('q', $rp)) { throw new \Exception('No `q` (query) parameter provided. Optionally review the original data by passing data=orig or original normalized data by passing data=norm'); }

        if ( array_key_exists('data', $rp) ) {

            if (!($rp['data'] == 'orig' || $rp['data'] == 'norm' || $rp['data'] == 'seek')) {
                throw new \Exception("Bad `data` parameter provided. Allowed `data` values are 'orig' (return unmodified source xml), 'norm' (return normalized json representation), 'seek' (return seekable)");
            }

            /** PERFORM CHECKS */
            // If DIČ is provided, drop the CZ prefix. Zero-pad IČ to match Ares XML file naming scheme.
            // Throw exception if q contains an invalid IČO, or if IČO not in database.
            $q = str_replace('cz', '', strtolower($rp['q']));
            $q = str_pad($q, 8, "0", STR_PAD_LEFT);
            if ((preg_match("/[0-9]{8}$/", $q) == false) or strlen($q) > 8) { throw new \Exception('Invalid IČO provided (non-numeric characters or IČO longer then 8 digits)'); }
            $path = '/var/www/html/data/glued-integrations-ares/data/VYSTUP/DATA/' . $q . '.xml';
            if (!file_exists($path)) { throw new \Exception('IČO not found. '.$path, 404); }

            /** RETURN ORIGINAL XML */
            if ( $rp['data'] == 'orig' ) {
                $data = file_get_contents($path);
                $response->getBody()->write($data);
                return $response->withHeader('Content-type', 'application/xml');
            }

            // Load file & check for unhandled namespaces
            $xml = simplexml_load_file($path);
            $ns  = $xml->getDocNamespaces();
            $nshandled = ['are' => '', 'xsi' => ''];
            $unhandled = array_keys(array_diff_key($ns, $nshandled));
            if ($unhandled !== []) {
                $this->logger->info('Unhandled XML namespaces.', ['file' => $path, 'unhandled' => $unhandled]);
            }

            // Extract data from the 'are' namespace, ignore the 'xsi' namespace.
            $data = $xml->children($ns['are']);

            // Normalize data
            $xp = $data->xpath('.//are:jmeno');
            foreach ($xp as $node) { $node[0] = mb_convert_case(get_object_vars($node)[0], MB_CASE_TITLE); }
            $xp = $data->xpath('.//are:prijmeni');
            foreach ($xp as $node) { $node[0] = mb_convert_case(get_object_vars($node)[0], MB_CASE_TITLE); }

            // Convert data to json, translate xml attributes.
            $json = \Laminas\Xml2Json\Xml2Json::fromXml($data->asXML(), false);

            /** RETURN NORMALIZED JSON */
            if ( $rp['data'] == 'norm' ) {
                $response->getBody()->write($json);
                return $response->withHeader('Content-type', 'application/json');
            }

            $orig = json_decode($json, true)['are:Odpoved']['are:Vypis_VREO'];
            $data = [];
            $data['name']  = $orig['are:Zakladni_udaje']['are:ObchodniFirma'];
            $data['regid'] = $orig['are:Zakladni_udaje']['are:ICO'];
            $data['adr']   = $orig['are:Zakladni_udaje']['are:Sidlo']['are:obec'];

            return $response->withJson($data, options: JSON_PRETTY_PRINT);
            /*
             *             $vreo['nat'][0]['regid'] = $zu['ICO'];
            $vreo['addr'][0]['kind']['main'] = 1;
            $vreo['addr'][0]['kind']['billing'] = 1;
            $vreo['addr'][0]['country'] = 'Czech republic';
            $vreo['addr'][0]['zip'] = $zu['Sidlo']['psc'] ?? null;
            $vreo['addr'][0]['region'] = null;
            $vreo['addr'][0]['district'] = $zu['Sidlo']['okres'] ?? null;
            $vreo['addr'][0]['locacity'] = $zu['Sidlo']['obec'] ?? null;
            $vreo['addr'][0]['quarter'] = $zu['Sidlo']['castObce'] ?? null;
            $vreo['addr'][0]['street'] = $zu['Sidlo']['ulice'] ?? null;
            $vreo['addr'][0]['streetnumber'] = $zu['Sidlo']['cisloOr'] ?? null;
            $vreo['addr'][0]['conscriptionnumber'] = $zu['Sidlo']['cisloPop'] ?? null;
            $vreo['addr'][0]['doornumber'] = null;
            $vreo['addr'][0]['floor'] = null;
            $nr = $this->utils->concat('/', [ $vreo['addr'][0]['conscriptionnumber'], $vreo['addr'][0]['streetnumber'] ]);
            $ql = $this->utils->concat('-', [ $vreo['addr'][0]['locacity'], $vreo['addr'][0]['quarter'] ]);
            if (!is_null($vreo['addr'][0]['street'])) {
                $st = $this->utils->concat(' ', [ $vreo['addr'][0]['street'], $nr ]);
            } else {
                $st = $this->utils->concat(' ', [ $vreo['addr'][0]['quarter'], $nr ]);
                $ql = $vreo['addr'][0]['locacity'];
            }
            $vreo['addr'][0]['full'] = $this->utils->concat(', ',  [ $st , $ql , $vreo['addr'][0]['zip'] , $vreo['addr'][0]['country'] ]);
            $vreo['addr'][0]['ext']['cz.ruian'] = $zu['Sidlo']['ruianKod'];
             */

/*
            if (array_key_exists('email', $jwt_claims) and $jwt_claims['email'] != "") {
                $transform
                    ->map(destination: 'email.0.value',   source: 'email')
                    ->map(destination: 'email.0._iss',    source: 'iss')
                    ->set(destination: 'email.0._iat',     value: time())
                    ->map(destination: 'email.0._sub',    source: 'sub')
                    ->set(destination: 'email.0._primary', value: 1)
                    ->set(destination: 'email.0._s',       value: 'email')
                    ->set(destination: 'email.0._v',       value: 1)
                    ->set(destination: 'email.0.uuid',     value: \Ramsey\Uuid\Uuid::uuid4()->toString());
            }
*/
        }

        return $response->withJson($data, options: JSON_PRETTY_PRINT);
    }

    public function json_r1(Request $request, Response $response, array $args = []): Response {
        $xml = new \SimpleXMLElement($data);
        $ns = $xml->getDocNamespaces();
        $data = $xml->children($ns['are']) ?? null;
        if (!$data) return [];
        $all = $data->children($ns['dtt'])->V;
        $all = json_decode(json_encode($all), true);
    }


    
}
