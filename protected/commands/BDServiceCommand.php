<?php
/**
 * =======================================
 * ###################################
 * MagnusBilling
 *
 * @package MagnusBilling
 * @author Adilson Leffa Magnus.
 * @copyright Copyright (C) 2005 - 2016 MagnusBilling. All rights reserved.
 * ###################################
 *
 * This software is released under the terms of the GNU Lesser General Public License v2.1
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 *
 * Please submit bug reports, patches, etc to https://github.com/magnusbilling/mbilling/issues
 * =======================================
 * Magnusbilling.com <info@magnusbilling.com>
 *
 */
class BDServiceCommand extends CConsoleCommand
{

    public function run($args)
    {

        define('LOGFILE', 'protected/runtime/BDServicePid.log');
        define('DEBUG', 0);

        if (!defined('PID')) {
            define("PID", "/var/run/magnus/BDServicePid.php");
        }

        if (Process::isActive()) {
            $log = DEBUG >= 1 ? Log::writeLog(LOGFILE, ' line:' . __LINE__ . " PROCESS IS ACTIVE ") : null;
            //die();
        } else {
            Process::activate();
        }
        $log = DEBUG >= 1 ? Log::writeLog(LOGFILE, ' line:' . __LINE__ . " START NOTIFY CLIENT ") : null;

        /*$_POST = array(

        "refid" => 23597,
        'message' => "TakaSend: Amount Of tk.10 SUCCESSFUL ON Mobile No, 01795559444. ID:TX117966843 Today Sale.155.9 ,Your Balance is Now 258.74  [Thankyou]"
        );*/
        $config = LoadConfig::getConfig();

        $userBD        = $config['global']['BDService_username'];
        $keyBD         = $config['global']['BDService_token'];
        $BDService_url = $config['global']['BDService_url'];

        $url = $BDService_url . "/ezzeapi/balance?user=$userBD&key=$keyBD";
        if (!$result = @file_get_contents($url, false)) {
            $result = '';
        }

        Configuration::model()->updateAll(array('config_value' => $result), 'config_key = :key',
            array(':key' => 'BDService_credit_provider'));

        $modelSendCreditSummary = SendCreditSummary::model()->findAll('confirmed = 0');

        foreach ($modelSendCreditSummary as $key => $sendCredit) {

            $idApi = $sendCredit->id;

            $url = $BDService_url . "/ezzeapi/status?id=" . $idApi . "&user=" . $userBD . "&key=" . $keyBD;

            if (!$result = @file_get_contents($url, false)) {
                $result = '';
            }

            print_r($result);

            if (preg_match("/SUCCESS/", $result)) {

                $modelRefill = Refill::model()->find('invoice_number = :key AND id_user = :key1',
                    array(
                        ':key'  => $sendCredit->id,
                        ':key1' => $sendCredit->id_user,
                    ));
                $message = explode("SUCCESS: ", $result);
                User::model()->updateByPk($sendCredit->id_user,
                    array(
                        'credit' => new CDbExpression('credit + ' . $modelRefill->credit),
                    )
                );

                $modelRefill->payment     = 1;
                $modelRefill->description = $modelRefill->description . '. Ref: ' . $message[1];
                $modelRefill->save();

                $sendCredit->confirmed = 1;
                $sendCredit->save();

                if ($sendCredit->idUser->id_user > 1) {

                    echo "\n\nIS A USER AGENT" . $sendCredit->idUser->id_user;

                    $modelRefill = Refill::model()->find('invoice_number = :key AND id_user = :key1',
                        array(
                            ':key'  => $sendCredit->id,
                            ':key1' => $sendCredit->idUser->id_user,
                        ));

                    User::model()->updateByPk($sendCredit->idUser->id_user,
                        array(
                            'credit' => new CDbExpression('credit + ' . $modelRefill->credit),
                        )
                    );

                    $modelRefill->payment     = 1;
                    $modelRefill->description = $modelRefill->description . '. Ref: ' . $message[1];
                    $modelRefill->save();

                }
            } else if (preg_match("/ERROR|CANCELLED/", $result)) {

                $sendCredit->confirmed = 3;
                $sendCredit->save();

                $modelRefill              = Refill::model()->find('invoice_number = :key', array(':key' => $sendCredit->id));
                $modelRefill->description = $modelRefill->description . '. Ref: ' . $result;
                $modelRefill->save();
            }
        }

    }
}