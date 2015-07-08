<?php if (!defined('APPLICATION')) exit();

require_once 'class.geoip.import.php';
require_once 'class.geoip.query.php';

// Define the plugin:
$PluginInfo['GeoIP'] = array(
    'Name' => 'Carmen Sandiego (GeoIP)',
    'Description' => "Provides Geo IP location functionality. This product uses GeoLite2 City data created by <a href=\"http://www.maxmind.com\">MaxMind</a>.",
    'Version' => '0.0.1',
    'RequiredApplications' => array('Vanilla' => '2.0.10'),
    'RequiredTheme' => FALSE,
    'RequiredPlugins' => FALSE,
    'HasLocale' => FALSE,
    'SettingsUrl' => '/plugin/geoip',
    'SettingsPermission' => 'Garden.AdminUser.Only',
    'Author' => "Deric D. Davis",
    'AuthorEmail' => 'deric.d@vanillaforums.com',
    'AuthorUrl' => 'http://www.vanillaforums.com'
);

class GeoipPlugin extends Gdn_Plugin {

    private $query;
    private $import;


    /**
     * Constructor:
     *
     * -Loads GeoIPQuery class into local property.
     * -Loads GeoIPImport class into local property.
     */
    public function __construct() {

        // Instantiate Query Object:
        $this->query  = new GeoipQuery();

        // Instantiate Import Object:
        $this->import = new GeoipImport();
    }

    /**
     * Creates GeoIP page in PluginController and runs dispatch
     * for sub-methods.
     *
     * @param $Sender Reference callback object.
     */
    public function PluginController_GeoIP_Create($Sender) {

        $Sender->Title('Carmen Sandiego Plugin (GeoIP)');
        $Sender->AddSideMenu('plugin/geoip');
        $Sender->Form = new Gdn_Form();

        $this->Dispatch($Sender, $Sender->RequestArgs);
    }

    /**
     * Main control routine for GeoIP.
     *
     * @param $Sender Reference callback object.
     */
    public function Controller_index($Sender) {

        $Sender->Permission('Garden.Settings.Manage');
        $Sender->SetData('PluginDescription',$this->GetPluginKey('Description'));

        $Validation = new Gdn_Validation();
        $ConfigurationModel = new Gdn_ConfigurationModel($Validation);
        $ConfigurationModel->SetField(array(
            'Plugin.GeoIP.doLogin'       => false,
            'Plugin.GeoIP.doDiscussions' => false,
        ));

        // Set the model on the form.
        $Sender->Form->SetModel($ConfigurationModel);

        // If seeing the form for the first time...
        if ($Sender->Form->AuthenticatedPostBack() === false) {
            // Apply the config settings to the form.
            $Sender->Form->SetData($ConfigurationModel->Data);

        } else {

            $Saved = $Sender->Form->Save();
            if ($Saved) {
                $Sender->StatusMessage = T("Your changes have been saved.");
            }
        }

        if (!empty($_GET['msg'])
        && stristr($_SERVER['HTTP_REFERER'],'/plugin/geoip')
        ) {
            $Sender->informMessage(t(Gdn::request()->get('msg')), 'Dismissable');
        }

        $Sender->Render($this->GetView('geoip.php'));
    }

    /**
     * Import GeoIP City Lite from Max Mind into MySQL.
     *
     * Redirects back to plugin index page.
     */
    public function Controller_import($Sender) {

        // Do Import:
        $imported = $this->import->run();
        if (!empty($imported)) {
            $msg = "Imported GeoIP City Lite successfully.";
        } else {
            $msg = "Failed to Import GeoIP City Lite";
        }

        $redirect = "/plugin/geoip?msg=".urlencode($msg);
        header("Location: {$redirect}");
        exit();
    }


    /**
     * Load GeoIP data upon login.
     *
     * @param $Sender Referencing object
     * @param array $Args Arguments provided
     * @return bool Returns true on success, false on failure.
     */
    public function UserModel_AfterSignIn_Handler($Sender, $Args=[]) {

        // Check IF feature is enabled for this plugin:
        if (C('Plugin.GeoIP.doLogin')==false) {
            return false;
        }

        $userID = Gdn::Session()->User->UserID;
        if (empty($userID)) {
            return false;
        }

        $this->setUserMetaGeo($userID);

        return true;
    }

    /**
     * Routine executed before a discussion.
     *
     * Method builds a list of IPs from discussion and comments and passes them
     * to Query object.
     *
     * @param $Sender Reference callback object.
     * @param array $Args Arguments being passed.
     * @return bool Returns true on success, false on failure.
     */
    public function DiscussionController_BeforeDiscussionDisplay_Handler($Sender, $Args=[]) {

        // Check IF feature is enabled for this plugin:
        if (C('Plugin.GeoIP.doDiscussions')==true) {

            // Create list of IPs from this discussion we want to look up.
            $ipList = [$Args['Discussion']->InsertIPAddress]; // Add discussion IP.
            foreach ($Sender->Data('Comments')->result() as $comment) {
                if (empty($comment->InsertIPAddress)) {
                    continue;
                }
                $ipList[] = $comment->InsertIPAddress;
            }

            // Get IP information for given IP list:
            $this->query->get($ipList);
        }
    }

    /**
     * Inserts flag and geoip information in a discussion near the name of the author.
     *
     * @param $Sender Reference callback object.
     * @param array $Args Arguments being passed.
     * @return bool Returns true on success, false on failure.
     */
    public function Base_AuthorInfo_Handler($Sender, $Args=[]) {

        // Check IF feature is enabled for this plugin:
        if (C('Plugin.GeoIP.doDiscussions')==false) {
            return false;
        }

        // Get IP based on context:
        if (!empty($Args['Comment']->InsertIPAddress)) { // If author is from comment.
            $targetIP = $Args['Comment']->InsertIPAddress;
        } else if (!empty($Args['Discussion']->InsertIPAddress)) { // If author is from discussion.
            $targetIP = $Args['Discussion']->InsertIPAddress;
        } else {
            return false;
        }

        // Make sure target IP is in local cache:
        if (!isset($this->query->localCache[$targetIP]) || $this->query->isLocalIP($targetIP)) {
            return false;
        }

        // Get Country Code:
        if (!empty($this->query->localCache[$targetIP])) {
            $countryCode  = strtolower($this->query->localCache[$targetIP]['country_iso_code']);
            $countryName  = $this->query->localCache[$targetIP]['country_name'];
            $cityName     = $this->query->localCache[$targetIP]['city_name'];

            if (!empty($cityName)) {
                $imgTitle  = "{$cityName}, {$countryName}";
            } else {
                $imgTitle  = "{$countryName}";
            }

            // Echo Image:
            if (!empty($countryCode)) {
                echo Img("/plugins/GeoIP/design/flags/{$countryCode}.png", ['alt'=>"({$countryName})", 'title'=> str_replace('"','\"',$imgTitle) ]);
            }
        }

        return true;
    }

    /**
     * Sets the user GeoIP information to UserMeta data.
     *
     * @param $userID
     * @return bool
     */
    private function setUserMetaGeo($userID) {
        if (empty($userID) || !is_numeric($userID)) {
            tigger_error("Invalid UserID passed to ".__METHOD__."()");
            return false;
        }

        $userInfo = GDN::UserModel()->GetID($userID);
        if (empty($userInfo) || (!is_array($userID) && !is_object($userInfo))) {
            trigger_error("Could not load user info for given UserID={$userID} in ".__METHOD__."()!", E_USER_WARNING);
            return false;
        }

        $userIP = $userInfo->LastIPAddress;
        if (empty($userIP)) {
            trigger_error("No IP address on record for target userID={$userID} in ".__METHOD__."()!", E_USER_NOTICE);
            return false;
        }

        if ($this->query->isLocalIp($userIP)) {

            $publicIP = $this->query->myIP();
            if (!$this->query->isIP($publicIP)) {
                return false;
            }
            $userIP = $publicIP;
        }

        $ipInfo = $this->query->get($userIP);
        $ipInfo = $ipInfo[$userIP];
        if (empty($ipInfo)) {
            trigger_error("Failed to get IP info in ".__METHOD__."()", E_USER_NOTICE);
            return false;
        }
        //echo "<pre>IP Info ".print_r($ipInfo,true)."</pre>\n";

        GDN::userMetaModel()->setUserMeta($userID, 'geo_country', $ipInfo['country_iso_code']);
        GDN::userMetaModel()->setUserMeta($userID, 'geo_latitude', $ipInfo['latitude']);
        GDN::userMetaModel()->setUserMeta($userID, 'geo_longitude', $ipInfo['longitude']);
        GDN::userMetaModel()->setUserMeta($userID, 'geo_city', utf8_encode($ipInfo['city_name']));
        GDN::userMetaModel()->setUserMeta($userID, 'geo_updated', time());

        return true;
    }

    /**
     * Gets user's GeoIP based information from user-meta data.
     *
     * @param $userID Target user's ID number.
     * @return array|bool Returns array of information or false on failure.
     */
    private function getUserMetaGeo($userID, $field='geo_%') {
        if (empty($userID) || !is_numeric($userID)) {
            tigger_error("Invalid UserID passed to ".__METHOD__."()", E_USER_WARNING);
            return false;
        }

        $meta  = GDN::userMetaModel()->getUserMeta($userID, $field);
        if (empty($meta)) {
            return false;
        }

        $output = [];
        foreach ($meta as $var => $value) {
            if (substr($var, 0, strlen('geo_')) == 'geo_') { // Make sure only to return geo_ info...
                // $output[substr($var,strlen('geo_'))] = $value;
                $output[$var] = $value;
            }
        }

        return $output;
    }

} // Closes GeoipPlugin.
