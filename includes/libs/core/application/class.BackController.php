<?php
namespace core\application
{
	use core\application\authentification\AuthentificationHandler;
	use core\application\event\Event;
	use core\db\Query;
	use core\tools\form\Form;
	use core\tools\PaginationHandler;
	use core\tools\Menu;
	use \Exception;

	/**
	 * Controller de backoffice de base
	 *
	 * @author Arnaud NICOLAS <arno06@gmail.com>
	 * @version 1.0
	 * @package core\application
	 */
	class BackController extends FrontController implements InterfaceController
	{

		/**
		 * @type String
		 */
		const EVENT_SUCCESSFUL_ADD      = "BOEvent_successful_add";

		/**
		 * @type String
		 */
		const EVENT_FAILED_ADD          = "BOEvent_failed_add";

		/**
		 * @type String
		 */
		const EVENT_SUCCESSFUL_EDIT   = "BOEVENT_SUCCESSFUL_EDIT";

		/**
		 * @type String
		 */
		const EVENT_FAILED_EDIT       = "BOEVENT_FAILED_EDIT";

		/**
		 * @type String
		 */
		const EVENT_SUCCESSUL_DELETE    = "BOEvent_successful_delete";

		/**
		 * Instance du model que le controller pourra manipuler
		 * @var BaseModel
		 */
		protected $model;

		/**
		 * Nom du formulaire à récupérer pour l'ajout et la modification
		 * @var String
		 */
		protected $formName;

		/**
		 * Nom de la classe en cours
		 * @var String
		 */
		protected $className;

		/**
		 * Tableau des champs à afficher dans la liste des enregistrements
		 * @var array
		 */
		protected $listTitle = array();

		/**
		 * Nombre d'entrée à afficher par page dans la liste des enregistrements
		 * @var int
		 */
		protected $nbItemsByPage = 15;

		/**
		 * Définit si on utilise ou non le syst&egrave;me de pagination dans la liste des enregistrements
		 * @var Boolean
		 */
		protected $usePaginationOnList = true;

		/**
		 * @var BOLabelList
		 */
		protected $titles;

		/**
		 * @var BOLabelList
		 */
		protected $h1;

		/**
		 * @var Menu
		 */
		protected $menu;

		/**
		 * @var BOActionList
		 */
		protected $actions;

		/**
		 * Constructor
		 * Se doit d'être appeler dans la classe fille
		 * Vérifie si l'utilisateur est identifié
		 * Définie le nom du controller (de la classe courante)
		 */
		public function __construct()
		{
			if(!AuthentificationHandler::is(AuthentificationHandler::ADMIN))
				Go::toBack();
			$class = explode("\\", get_class($this));
			$this->className = end($class);
            $this->formName = $this->className;
			Autoload::addScript("Backoffice");
			$this->h1 = new BOLabelList("h1", ucfirst($this->className));
			$this->titles = new BOLabelList("titles", ucfirst($this->className));
			$this->actions = new BOActionList();
			$this->actions->enable('view', 'view', true);
			$this->actions->enable('edit', 'edit', true);
			$this->actions->enable('delete', 'delete', true);
			$this->actions->enable('listing', 'listing', false);
			$this->actions->enable('add', 'add', false);
			$this->menu = new Menu(Core::$path_to_application.'/modules/back/menu.json');
		}

		/**
		 * @param null $pSmarty
		 * @param bool $pDisplay
		 * @return string
		 */
		public function renderHTML($pSmarty = null, $pDisplay = true)
		{
			$this->addContent("actions", $this->actions->toArray());
			$this->addContent('menu_items', $this->menu->retrieveItems());
			return parent::renderHTML($pSmarty, $pDisplay);
		}

		/**
		 * Méthode appelé par défault en cas de non-existance d'action
		 * Renvoie automatiquement vers l'action "lister"
		 * @return void
		 */
		public function index()
		{
			Go::toBack($this->className, "listing");
		}

		/**
		 * Méthode d'ajout d'une nouvel entrée
		 * Définie le formulaire
		 * Vérifie les données du formulaire
		 * Déclenche l'ajout dans le model
		 * @return void
		 */
		public function add()
		{
			if(!$this->actions->isEnabled('add'))
				Go::to404();
			$this->setTitle($this->titles->get('add'));
			$this->setTemplate("default", "form");
			try
			{
				$form = new Form($this->formName);
			}
			catch(Exception $e)
			{
				$form = new Form($this->formName, false);
				$inputs = $this->model->generateInputsFromDescribe();
				foreach($inputs as $nam=>$inp)
				{
					$form->setInput($nam, $inp);
				}
			}

			if($form->isValid())
			{
				if($this->model->insert($form->getValues()))
				{
					$this->addContent("confirmation", Dictionary::term("backoffice.forms.addDone"));
					$this->dispatchEvent(new Event(self::EVENT_SUCCESSFUL_ADD));
				}
				else
				{
					$this->addContent("error", Dictionary::term("backoffice.forms.errorSQL"));
					$this->dispatchEvent(new Event(self::EVENT_FAILED_ADD));
				}
				$id = $this->model->getInsertId();
				$form->setUploadFileName($id);
			}
			else
				$this->addContent("error", $form->getError());
			$this->addForm("instance", $form);
			$this->addContent("h1", $this->h1->get('add'));
		}

		/**
		 * Méthode permettant de lister toutes les entrées du model
		 * Gestion automatique du ORDER BY
		 * @param String $pCondition		Condition souhaitée pour la requête SQL
		 * @return void
		 */
		public function listing($pCondition = null)
		{
			if(!$this->actions->isEnabled("listing"))
				Go::to404();
			$this->setTitle($this->titles->get('listing'));
			$this->setTemplate("default", "listing");
			$this->addContent("titles", $this->listTitle);
			$this->addContent("id", $this->model->id);
			if(!$pCondition)
				$pCondition = Query::condition();
			$pConditionCount = clone $pCondition;

			for($i=0,$max = count($this->listTitle); $i< $max; $i++)
			{
				if(isset($_GET["order"])&&in_array($_GET["order"], $this->listTitle[$i]))
				{
					$pCondition->order($_GET["order"],(isset($_GET["by"])?$_GET["by"]:"ASC"));
					$i = $max;
				}
			}
			if($this->usePaginationOnList)
			{
				$nbDatas =  $this->model->count($pConditionCount);
				$currentPage = isset($_GET["page"])?$_GET["page"]:1;
				$pagination = new PaginationHandler($currentPage, $this->nbItemsByPage, $nbDatas);
				$pCondition->limit($pagination->first, $pagination->number);
				$data = $this->model->all($pCondition);
				$this->addContent("paginationInfo", $pagination->getPaginationInfo());
			}
			else
				$data =  $this->model->all($pCondition);
			$this->addContent("liste", $data);
			$this->addContent("h1", $this->h1->get('listing'));
		}

		/**
		 * Méthode de modification d'une entrée
		 * Récup&egrave;re les données via le model et les injecte dans le formulaire
		 * @return boolean
		 */
		public function edit()
		{
			if(!$this->actions->isEnabled('edit'))
				Go::to404();
			$this->setTitle($this->titles->get('edit'));

			try
			{
				$form = new Form($this->formName);
			}
			catch(Exception $e)
			{
				$form = new Form($this->formName, false);
				$inputs = $this->model->generateInputsFromDescribe();
				foreach($inputs as $nam=>$inp)
				{
					$form->setInput($nam, $inp);
				}
			}

			$data = $this->model->getTupleById($_GET["id"]);
			if(!$data)
				Go::toBack($this->className);
			$form->injectValues($data);

			if($form->isValid())
			{
				if($this->model->updateById($_GET["id"],$form->getValues()))
				{
					$this->addContent("confirmation", Dictionary::term("backoffice.forms.editDone"));
					$this->dispatchEvent(new Event(self::EVENT_SUCCESSFUL_EDIT));
				}
				else
				{
					$this->addContent("error", Dictionary::term("backoffice.forms.errorSQL"));
					$this->dispatchEvent(new Event(self::EVENT_FAILED_EDIT));
				}
				$id = $_GET["id"];
				$form->setUploadFileName($id);
			}
			else
				$this->addContent("error", $form->getError());
			$this->setTemplate("default", "form");
			$this->addContent("id", $this->model->id);
			$this->addForm("instance", $form);
			$this->addContent("h1", $this->h1->get('edit'));
		}

		/**
		 * Méthode de suppression d'une entrée
		 * Renvoie systématiquement à l'action "lister"
		 * @return void
		 */
		public function delete()
		{
			if(!$this->actions->isEnabled('delete'))
				Go::to404();
			$this->model->deleteById($_GET["id"]);
			$this->dispatchEvent(new Event(self::EVENT_SUCCESSUL_DELETE));
			Go::toBack($this->className);
		}

		public function view()
		{
			if(!$this->actions->isEnabled('view'))
				Go::to404();
			$this->setTitle($this->titles->get('view'));

			$data = $this->model->getTupleById($_GET["id"]);
			if(!$data)
				Go::to404();

			$this->setTemplate("default", "view");
			$this->addContent("data", $data);
			$this->addContent("h1", $this->h1->get('view'));
		}

		/**
		 * @param string $pField
		 * @param string $pLabel
		 * @param bool $pOrder
		 * @return void
		 */
		protected function addColumnToList($pField, $pLabel, $pOrder = true)
		{
			$this->listTitle[] = array("champ"=>$pField, "label"=>$pLabel, "order"=>$pOrder);
		}
	}

	Class BOActionList
	{
		private $actions = array();

		public function enable($pActionName, $pAction = null, $pApplyToEntry = false)
		{
			if(!$pAction)
				$pAction = $pActionName;
			$this->actions[$pActionName] = array('name'=>$pAction, 'applyToEntry'=>$pApplyToEntry);
		}

		public function disable($pActionName)
		{
			if(isset($this->actions[$pActionName]))
				unset($this->actions[$pActionName]);
		}

		public function isEnabled($pActionName)
		{
			return isset($this->actions[$pActionName]);
		}

		public function toArray()
		{
			return $this->actions;
		}
	}

	/**
	 * @package backoffice
	 */
	class BOLabelList
	{
		/**
		 * @var string
		 */
		private $className;

		/**
		 * @var string
		 */
		private $id;

		/**
		 * @param string $pId
		 * @param string $pClass
		 */
		public function __construct($pId, $pClass)
		{
			$this->id = $pId;
			$this->className = $pClass;
		}

		/**
		 * @param string $pName
		 * @return mixed
		 */
		public function get($pName)
		{
			return sprintf(Dictionary::term('backoffice.'.$this->id.'.'.$pName), $this->className);
		}
	}
}