<?php
namespace OPNsense\NetworkProvisioner;

/**
 * Class IndexController
 * @package OPNsense\NetworkProvisioner
 */
class IndexController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->title = gettext("Network Provisioner");
        $this->view->pick('OPNsense/NetworkProvisioner/index');
    }
}
