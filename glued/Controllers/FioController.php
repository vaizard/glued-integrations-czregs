<?php

declare(strict_types=1);

namespace Glued\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FioController extends AbstractController
{

    //
    // React UI ingress
    //
    public function accounts_sync(Request $request, Response $response, array $args = []): Response {

      // Args & init
      $builder = new JsonResponseBuilder('fin.sync.banks', 1);
      if (!array_key_exists('uid', $args)) {
        $payload = $builder->withMessage(__('Syncing multiple accounts not supported (yet).'))->withCode(500)->build();
        return $response->withJson($payload); 
      }
      $account_uid = (int)$args['uid'];

      // Fetch (locally stored) account properties
      $this->db->where('c_uid', $account_uid);
      $account_obj = $this->db->getOne('t_fin_accounts', ['c_json', 'c_ts_synced']);
      if (!is_array($account_obj)) {
        $payload = $builder->withMessage(__('Account not found.'))->withCode(404)->build();
        return $response->withJson($payload);
      }
      $account = json_decode($account_obj['c_json'], true);
      $account['synced'] = $account_obj['c_ts_synced'];


      // Fetch all (locally stored) transactions
      $this->db->where('c_account_id', $account_uid);
      $this->db->orderBy('c_trx_dt', 'Desc');
      $this->db->orderBy('c_ext_trx_id', 'Desc');
      $local_trxs = $this->db->get('t_fin_trx', null, ['c_trx_dt','c_ext_trx_id']);


      // Throttle in case of too many requests
      $t1 = Carbon::createFromFormat('Y-m-d H:i:s', $account['synced'], 'UTC'); // TODO: Replace hardcoded mysql timezone (UTC). See https://stackoverflow.com/questions/2934258/how-do-i-get-the-current-time-zone-of-mysql
      $t2 = Carbon::now();
      $tdiff = $t2->diffinSeconds($t1);
      if ($tdiff < 30) { 
        // TODO replace hard throttling with queuing
        $payload = $builder->withMessage(__('Account synchronization throttled. Last sync '.$tdiff.' seconds ago, please retry in ').(30 - $tdiff).' '.__('seconds'))->withCode(429)->build();
        return $response->withJson($payload);
      }


      // Set the fetch intervals & update sync time in account properties
      $date_from = (string)(new \DateTime($args['from'] ?? $local_trxs[0]['c_trx_dt'] ?? '2000-01-01'))->modify('-1 day')->format('Y-m-d');
      $date_to = (string)($args['to'] ?? date('Y-m-d'));
      $this->db->where('c_uid', $account_uid);
      $this->db->update('t_fin_accounts', [ 'c_ts_synced' => $this->db->now() ]);

      // FIO.CZ ACCOUNTS ================================================

      if ($account['type'] === 'fio_cz') {
        // Get transactions from the api, return tranactions not known locally
        $uri = 'https://www.fio.cz/ib_api/rest/periods/'.$account['config']['token'].'/'.$date_from.'/'.$date_to.'/transactions.json';
        $data = (array)json_decode($this->utils->fetch_uri($uri), true);
        if (!array_key_exists('accountStatement', $data)) { throw new HttpInternalServerErrorException( $request, __('Syncing with remote server failed.')); }
        $fin = new FinUtils();
        $data = $fin->fio_cz($data['accountStatement']['transactionList']['transaction'], [ 'account_id' => $account_uid ], $local_trxs);

        // Insert new transactions
        foreach ($data as $helper) {       
              $insertdata[] = [
                "c_json" => json_encode($helper),
                "c_account_id" => $account_uid
              ];
        }
        if (isset($insertdata)) {
          $ids = $this->db->insertMulti('t_fin_trx', $insertdata);
          $query = 'UPDATE `t_fin_trx` SET `c_json` = JSON_SET(`c_json`, "$.id", `c_uid`) WHERE (NOT `c_uid` = c_json->>"$.id") or (NOT JSON_CONTAINS_PATH(c_json, "one", "$.id"));';
          $this->db->rawQuery($query);
        }


        // Respond to client
        $msg = (isset($ids) ? count($ids).' items synced.' : 'Even with remote source, nothing to sync.');
        $payload = $builder->withMessage($msg)->withData((array)$data)->withCode(200)->build();
        return $response->withJson($payload);
      } 

      // LOCAL ACCOUNTS ================================================

      else {
        $payload = $builder->withMessage('Primary account data held locally, nothing to sync.')->withCode(200)->build();
        return $response->withJson($payload);
      }
    }

    
}
