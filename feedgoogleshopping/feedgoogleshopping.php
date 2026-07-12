<?php
if (!defined('_PS_VERSION_')) {
	exit;
}

class FeedGoogleShopping extends Module
{
	public function __construct()
	{
		$this->name = 'feedgoogleshopping';
		$this->tab = 'front_office_features';
		$this->version = '1.0.0';
		$this->author = 'Francisco Antonio Martin Hersog';
		$this->need_instance = 0;
		$this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];
		$this->bootstrap = true;

		parent::__construct();

		$this->displayName = $this->l('Feed Export for Google Shopping');
		$this->description = $this->l('Generates a public XML producto feed compatible with Google Merchant Center.');
	}

	public function install()
	{
		return parent::install();
	}

	public function uninstall()
	{
		return parent::uninstall();
	}
}
