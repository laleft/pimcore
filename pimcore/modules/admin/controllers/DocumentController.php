<?php
/**
 * Pimcore
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.pimcore.org/license
 *
 * @copyright  Copyright (c) 2009-2010 elements.at New Media Solutions GmbH (http://www.elements.at)
 * @license    http://www.pimcore.org/license     New BSD License
 */

class Admin_DocumentController extends Pimcore_Controller_Action_Admin {

    /**
     * @var Document_Service
     */
    protected $_documentService;

    public function init() {
        parent::init();

        // check permissions
        $notRestrictedActions = array();
        if (!in_array($this->_getParam("action"), $notRestrictedActions)) {
            if (!$this->getUser()->isAllowed("documents")) {

                $this->_redirect("/admin/login");
                die();
            }
        }

        $this->_documentService = new Document_Service($this->getUser());
    }

    public function getPredefinedPropertiesAction() {

        $list = new Property_Predefined_List();
        $list->setCondition("ctype = 'document'");
        $list->setOrder("ASC");
        $list->setOrderKey("name");
        $list->load();

        $properties = array();
        foreach ($list->getProperties() as $type) {
            $properties[] = $type;
        }

        $this->_helper->json(array("properties" => $properties));
    }

    public function getDataByIdAction() {

        $document = Document::getById($this->_getParam("id"));
        $document->getPermissionsForUser($this->getUser());
        if ($document->isAllowed("view")) {
            $this->_helper->json($document);
        }

        $this->_helper->json(array("success" => false, "message" => "missing_permission"));
    }

    public function treeGetRootAction() {

        $id = 1;
        if ($this->_getParam("id")) {
            $id = intval($this->_getParam("id"));
        }

        $root = Document::getById($id);
        $root->getPermissionsForUser($this->getUser());

        if ($root->isAllowed("list")) {
            $this->_helper->json($this->getTreeNodeConfig($root));
        }

        $this->_helper->json(array("success" => false, "message" => "missing_permission"));
    }

    public function treeGetChildsByIdAction() {

        $document = Document::getById($this->_getParam("node"));

        $documents = array();
        if ($document->hasChilds()) {
            $limit = intval($this->_getParam("limit"));
            if (!$this->_getParam("limit")) {
                $limit = 100000000;
            }
            $offset = intval($this->_getParam("start"));

            $list = new Document_List();
            $list->setCondition("parentId = ?", $document->getId());
            $list->setOrderKey("index");
            $list->setOrder("asc");
            $list->setLimit($limit);
            $list->setOffset($offset);

            $childsList = $list->load();

            foreach ($childsList as $childDocument) {
                // get current user permissions
                $childDocument->getPermissionsForUser($this->getUser());
                // only display document if listing is allowed for the current user
                if ($childDocument->isAllowed("list")) {
                    $documents[] = $this->getTreeNodeConfig($childDocument);
                }
            }
        }

        if ($this->_getParam("limit")) {
            $this->_helper->json(array(
                "total" => $document->getChildAmount(),
                "nodes" => $documents
            ));
        }
        else {
            $this->_helper->json($documents);
        }

        $this->_helper->json(false);
    }

    public function addAction() {

        $success = false;

        // check for permission
        $parentDocument = Document::getById(intval($this->_getParam("parentId")));
        $parentDocument->getPermissionsForUser($this->getUser());
        if ($parentDocument->isAllowed("create")) {
            $intendedPath = $parentDocument->getFullPath() . "/" . $this->_getParam("key");

            if (!Document_Service::pathExists($intendedPath)) {

                $createValues = array(
                    "userOwner" => $this->getUser()->getId(),
                    "userModification" => $this->getUser()->getId(),
                    "published" => false
                );

                $createValues["key"] = $this->_getParam("key");

                // check for a docType
                if ($this->_getParam("docTypeId") && is_numeric($this->_getParam("docTypeId"))) {
                    $docType = Document_DocType::getById(intval($this->_getParam("docTypeId")));
                    $createValues["template"] = $docType->getTemplate();
                    $createValues["controller"] = $docType->getController();
                    $createValues["action"] = $docType->getAction();
                    $createValues["module"] = $docType->getModule();
                } else if($this->_getParam("type") == "page" || $this->_getParam("type") == "snippet" || $this->_getParam("type") == "email") {
                    $createValues["controller"] = Pimcore_Config::getSystemConfig()->documents->default_controller;
                    $createValues["action"] = Pimcore_Config::getSystemConfig()->documents->default_action;
                }

                switch ($this->_getParam("type")) {
                    case "page":
                        $document = Document_Page::create($this->_getParam("parentId"), $createValues);
                        $success = true;
                        break;
                    case "snippet":
                        $document = Document_Snippet::create($this->_getParam("parentId"), $createValues);
                        $success = true;
                        break;
                    case "email": //ckogler
                        $document = Document_Email::create($this->_getParam("parentId"), $createValues);
                        $success = true;
                        break;
                    case "link":
                        $document = Document_Link::create($this->_getParam("parentId"), $createValues);
                        $success = true;
                        break;
                    case "hardlink":
                        $document = Document_Hardlink::create($this->_getParam("parentId"), $createValues);
                        $success = true;
                        break;
                    case "folder":
                        $document = Document_Folder::create($this->_getParam("parentId"), $createValues);
                        $document->setPublished(true);
                        try {
                            $document->save();
                            $success = true;
                        } catch (Exception $e) {
                            $this->_helper->json(array("success" => false, "message" => $e->getMessage()));
                        }
                        break;
                    default:
                        Logger::debug("Unknown document type, can't add [ " . $this->_getParam("type") . " ] ");
                        break;
                }
            }
            else {
                Logger::debug("prevented adding a document because document with same path+key [ $intendedPath ] already exists");
            }
        }
        else {
            Logger::debug("prevented adding a document because of missing permissions");
        }

        if ($success) {
            $this->_helper->json(array(
                "success" => $success,
                "id" => $document->getId(),
                "type" => $document->getType()
            ));
        }
        else {
            $this->_helper->json(array(
                "success" => $success
            ));
        }


    }

    public function deleteAction()
    {
        if ($this->_getParam("type") == "childs") {

            $parentDocument = Document::getById($this->_getParam("id"));

            $list = new Document_List();
            $list->setCondition("path LIKE '" . $parentDocument->getFullPath() . "/%'");
            $list->setLimit(intval($this->_getParam("amount")));
            $list->setOrderKey("LENGTH(path)", false);
            $list->setOrder("DESC");

            $documents = $list->load();

            $deletedItems = array();
            foreach ($documents as $document) {
                $deletedItems[] = $document->getFullPath();
                $document->delete();
            }

            $this->_helper->json(array("success" => true, "deleted" => $deletedItems));

        } else if($this->_getParam("id")) {
            $document = Document::getById($this->_getParam("id"));
            $document->getPermissionsForUser($this->getUser());

            if ($document->isAllowed("delete")) {
                Element_Recyclebin_Item::create($document, $this->getUser());
                $document->delete();

                $this->_helper->json(array("success" => true));
            }
        }

        $this->_helper->json(array("success" => false, "message" => "missing_permission"));
    }

    public function deleteInfoAction()
    {
        $hasDependency = false;

        try {
            $document = Document::getById($this->_getParam("id"));
            $hasDependency = $document->getDependencies()->isRequired();
        }
        catch (Exception $e) {
            Logger::err("failed to access document with id: " . $this->_getParam("id"));
        }

        $deleteJobs = array();

        // check for childs
        if($document instanceof Document) {

            $deleteJobs[] = array(array(
                "url" => "/admin/recyclebin/add",
                "params" => array(
                    "type" => "document",
                    "id" => $document->getId()
                )
            ));

            $hasChilds = $document->hasChilds();
            if (!$hasDependency) {
                $hasDependency = $hasChilds;
            }

            $childs = 0;
            if($hasChilds) {
                // get amount of childs
                $list = new Document_List();
                $list->setCondition("path LIKE '" . $document->getFullPath() . "/%'");
                $childs = $list->getTotalCount();

                if($childs > 0) {
                    $deleteObjectsPerRequest = 5;
                    for($i=0; $i<ceil($childs/$deleteObjectsPerRequest); $i++) {
                        $deleteJobs[] = array(array(
                            "url" => "/admin/document/delete",
                            "params" => array(
                                "step" => $i,
                                "amount" => $deleteObjectsPerRequest,
                                "type" => "childs",
                                "id" => $document->getId()
                            )
                        ));
                    }
                }
            }

            // the object itself is the last one
            $deleteJobs[] = array(array(
                "url" => "/admin/document/delete",
                "params" => array(
                    "id" => $document->getId()
                )
            ));
        }

        $this->_helper->json(array(
              "hasDependencies" => $hasDependency,
              "childs" => $childs,
              "deletejobs" => $deleteJobs
        ));
    }

    public function getRequiresDependenciesAction() {
        $id = $this->_getParam("id");
        $document = Document::getById($id);
        if ($document instanceof Document) {
            $dependencies = Element_Service::getRequiresDependenciesForFrontend($document->getDependencies());
            $this->_helper->json($dependencies);
        }
        $this->_helper->json(false);
    }

    public function getRequiredByDependenciesAction() {
        $id = $this->_getParam("id");
        $document = Document::getById($id);
        if ($document instanceof Document) {
            $dependencies = Element_Service::getRequiredByDependenciesForFrontend($document->getDependencies());
            $this->_helper->json($dependencies);
        }
        $this->_helper->json(false);
    }

    public function updateAction() {

        $success = false;
        $allowUpdate = true;

        $document = Document::getById($this->_getParam("id"));

        // check for permissions
        $document->getPermissionsForUser($this->getUser());

        if ($document->isAllowed("settings")) {

            // if the position is changed the path must be changed || also from the childs
            if ($this->_getParam("parentId")) {
                $parentDocument = Document::getById($this->_getParam("parentId"));

                //check if parent is changed
                if ($document->getParentId() != $parentDocument->getId()) {

                    if(!$parentDocument->isAllowed("create")){
                        throw new Exception("Prevented moving document - no create permission on new parent ");
                    }

                    $intendedPath = $parentDocument->getPath();
                    $pKey = $parentDocument->getKey();
                    if (!empty($pKey)) {
                        $intendedPath .= $parentDocument->getKey() . "/";
                    }

                    $documentWithSamePath = Document::getByPath($intendedPath . $document->getKey());

                    if ($documentWithSamePath != null) {
                        $allowUpdate = false;
                    }
                }
            }

            if ($allowUpdate) {
                if ($this->_getParam("key") || $this->_getParam("parentId")) {
                    $oldPath = $document->getPath() . $document->getKey();
                }

                $blockedVars = array("controller", "action", "module");

                if(!$document->isAllowed("rename") && $this->_getParam("key")){
                    $blockedVars[]="key";
                    Logger::debug("prevented renaming document because of missing permissions ");
                }

                foreach ($this->_getAllParams() as $key => $value) {
                    if (!in_array($key, $blockedVars)) {
                        $document->setValue($key, $value);
                    }
                }

                // if changed the index change also all documents on the same level
                if ($this->_getParam("index") !== null) {
                    $list = new Document_List();
                    $list->setCondition("parentId = ? AND id != ?", array($this->_getParam("parentId"), $document->getId()));
                    $list->setOrderKey("index");
                    $list->setOrder("asc");
                    $childsList = $list->load();

                    $count = 0;
                    foreach ($childsList as $child) {
                        if ($count == intval($this->_getParam("index"))) {
                            $count++;
                        }
                        $child->setIndex($count);
                        $child->save();
                        $count++;
                    }
                }

                $document->setUserModification($this->getUser()->getId());
                try {
                    $document->save();
                    $success = true;
                } catch (Exception $e) {
                    $this->_helper->json(array("success" => false, "message" => $e->getMessage()));
                }
            }
            else {
                Logger::debug("Prevented moving document, because document with same path+key already exists.");
            }
        } else if ($document->isAllowed("rename") &&  $this->_getParam("key") ) {
            //just rename
            try {
                    $document->setKey($this->_getParam("key") );
                    $document->save();
                    $success = true;
                } catch (Exception $e) {
                    $this->_helper->json(array("success" => false, "message" => $e->getMessage()));
                }
        }
        else {
            Logger::debug("Prevented update document, because of missing permissions.");
        }

        $this->_helper->json(array("success" => $success));
    }

    public function docTypesAction() {

        if ($this->_getParam("data")) {
            if ($this->getUser()->isAllowed("document_types")) {
                if ($this->_getParam("xaction") == "destroy") {

                    $id = Zend_Json::decode($this->_getParam("data"));

                    $type = Document_DocType::getById($id);
                    $type->delete();

                    $this->_helper->json(array("success" => true, "data" => array()));
                }
                else if ($this->_getParam("xaction") == "update") {

                    $data = Zend_Json::decode($this->_getParam("data"));

                    // save type
                    $type = Document_DocType::getById($data["id"]);

                    $type->setValues($data);
                    $type->save();

                    $this->_helper->json(array("data" => $type, "success" => true));
                }
                else if ($this->_getParam("xaction") == "create") {
                    $data = Zend_Json::decode($this->_getParam("data"));
                    unset($data["id"]);

                    // save type
                    $type = Document_DocType::create();
                    $type->setValues($data);

                    $type->save();

                    $this->_helper->json(array("data" => $type, "success" => true));
                }
            }
        }
        else {
            // get list of types
            $list = new Document_DocType_List();

            if($this->_getParam("sort")) {
                $list->setOrderKey($this->_getParam("sort"));
                $list->setOrder($this->_getParam("dir"));
            }

            $list->load();

            $docTypes = array();
            foreach ($list->getDocTypes() as $type) {
                $docTypes[] = $type;
            }

            $this->_helper->json(array("data" => $docTypes, "success" => true, "total" => count($docTypes)));
        }

        $this->_helper->json(false);
    }

    public function getDocTypesAction() {

        $list = new Document_DocType_List();
        if ($this->_getParam("type")) {
            $type = $this->_getParam("type");
            if (Document_Service::isValidType($type)) {
                $list->setCondition("type = ?", $type);
            }
        }
        $list->setOrderKey(array("priority", "name"));
        $list->setOrder(array("desc", "ASC"));
        $list->load();


        $docTypes = array();
        foreach ($list->getDocTypes() as $type) {
            $docTypes[] = $type;
        }

        $this->_helper->json(array("docTypes" => $docTypes));
    }

    public function getPathForIdAction() {

        $document = Document::getById($this->_getParam("id"));
        die($document->getPath() . $document->getKey());
    }

    public function deleteVersionAction() {
        $version = Version::getById($this->_getParam("id"));
        $version->delete();

        $this->_helper->json(array("success" => true));
    }

    public function versionUpdateAction() {

        $data = Zend_Json::decode($this->_getParam("data"));

        $version = Version::getById($data["id"]);
        $version->setPublic($data["public"]);
        $version->setNote($data["note"]);
        $version->save();

        $this->_helper->json(array("success" => true));
    }

    public function versionToSessionAction() {

        $version = Version::getById($this->_getParam("id"));
        $document = $version->loadData();

        $key = "document_" . $document->getId();
        $session = new Zend_Session_Namespace("pimcore_documents");
        $session->$key = $document;

        $this->removeViewRenderer();
    }

    public function publishVersionAction() {

        $this->versionToSessionAction();

        $version = Version::getById($this->_getParam("id"));
        $document = $version->loadData();

        $currentDocument = Document::getById($document->getId());
        $currentDocument->getPermissionsForUser($this->getUser());
        if ($currentDocument->isAllowed("publish")) {
            $document->setPublished(true);
            try {
                
                $document->setKey($currentDocument->getKey());
                $document->setPath($currentDocument->getPath());
                
                $document->save();
            } catch (Exception $e) {
                $this->_helper->json(array("success" => false, "message" => $e->getMessage()));
            }
        }

        $this->_helper->json(array("success" => true));
    }

    public function createSiteAction() {

        $domains = $this->_getParam("domains");
        $domains = str_replace(" ", "", $domains);
        $domains = explode(",", $domains);

        $site = Site::create(array(
            "rootId" => intval($this->_getParam("id")),
            "domains" => $domains
        ));
        $site->save();

        $this->_helper->json($site);
    }

    public function updateSiteAction() {

        $domains = $this->_getParam("domains");
        $domains = str_replace(" ", "", $domains);
        $domains = explode(",", $domains);

        $site = Site::getByRootId(intval($this->_getParam("id")));
        $site->setDomains($domains);
        $site->save();

        $this->_helper->json($site);
    }

    public function removeSiteAction() {

        $site = Site::getByRootId(intval($this->_getParam("id")));
        $site->delete();

        $this->_helper->json(array("success" => true));
    }

    public function copyInfoAction() {

        $transactionId = time();
        $pasteJobs = array();
        $session = new Zend_Session_Namespace("pimcore_copy");
        $session->$transactionId = array("idMapping" => array());

        if ($this->_getParam("type") == "recursive" || $this->_getParam("type") == "recursive-update-references") {

            $document = Document::getById($this->_getParam("sourceId"));

            // first of all the new parent
            $pasteJobs[] = array(array(
                "url" => "/admin/document/copy",
                "params" => array(
                    "sourceId" => $this->_getParam("sourceId"),
                    "targetId" => $this->_getParam("targetId"),
                    "type" => "child",
                    "transactionId" => $transactionId,
                    "saveParentId" => true
                )
            ));


            $childIds = array();
            if($document->hasChilds()) {
                // get amount of childs
                $list = new Document_List();
                $list->setCondition("path LIKE '" . $document->getFullPath() . "/%'");
                $list->setOrderKey("LENGTH(path)", false);
                $list->setOrder("ASC");
                $childIds = $list->loadIdList();

                if(count($childIds) > 0) {
                    foreach ($childIds as $id) {
                        $pasteJobs[] = array(array(
                            "url" => "/admin/document/copy",
                            "params" => array(
                                "sourceId" => $id,
                                "targetParentId" => $this->_getParam("targetId"),
                                "sourceParentId" => $this->_getParam("sourceId"),
                                "type" => "child",
                                "transactionId" => $transactionId
                            )
                        ));
                    }
                }
            }


            // add id-rewrite steps
            if($this->_getParam("type") == "recursive-update-references") {
                for($i=0; $i<(count($childIds)+1); $i++) {
                    $pasteJobs[] = array(array(
                        "url" => "/admin/document/copy-rewrite-ids",
                        "params" => array(
                            "transactionId" => $transactionId,
                            "_dc" => uniqid()
                        )
                    ));
                }
            }
        }
        else if ($this->_getParam("type") == "child" || $this->_getParam("type") == "replace") {
            // the object itself is the last one
            $pasteJobs[] = array(array(
                "url" => "/admin/document/copy",
                "params" => array(
                    "sourceId" => $this->_getParam("sourceId"),
                    "targetId" => $this->_getParam("targetId"),
                    "type" => $this->_getParam("type"),
                    "transactionId" => $transactionId
                )
            ));
        }


        $this->_helper->json(array(
            "pastejobs" => $pasteJobs
        ));
    }

    public function copyRewriteIdsAction () {

        $session = new Zend_Session_Namespace("pimcore_copy");
        $idStore = $session->{$this->_getParam("transactionId")};

        if(!array_key_exists("rewrite-stack",$idStore)) {
            $idStore["rewrite-stack"] = array_values($idStore["idMapping"]);
        }

        $id = array_shift($idStore["rewrite-stack"]);
        $document = Document::getById($id);

        // rewriting elements only for snippets and pages
        if($document instanceof Document_PageSnippet) {
            $elements = $document->getElements();
            foreach ($elements as &$element) {
                if(method_exists($element, "rewriteIds")) {
                    $element->rewriteIds($idStore["idMapping"]);
                }
            }
            $document->setElements($elements);
        } else if ($document instanceof Document_Hardlink) {
            if($document->getSourceId() && array_key_exists((int) $document->getSourceId(), $idStore["idMapping"])) {
                $document->setSourceId($idStore["idMapping"][(int) $document->getSourceId()]);
            }
        } else if ($document instanceof Document_Link) {
            if($document->getLinktype() == "internal" && $document->getInternalType() == "document" && array_key_exists((int) $document->getInternal(), $idStore["idMapping"])) {
                $document->setInternal($idStore["idMapping"][(int) $document->getInternal()]);
            }
        }

        // rewriting properties
        $properties = $document->getProperties();
        foreach ($properties as &$property) {
            if(!$property->isInherited()) {
                if($property->getType() == "document") {
                    if($property->getData() instanceof Document) {
                        if(array_key_exists((int) $property->getData()->getId(), $idStore["idMapping"])) {
                            $property->setData(Document::getById($idStore["idMapping"][(int) $property->getData()->getId()]));
                        }
                    }
                }
            }
        }
        $document->setProperties($properties);

        
        $document->save();
        

        // write the store back to the session
        $session->{$this->_getParam("transactionId")} = $idStore;

        $this->_helper->json(array(
            "success" => true,
            "id" => $id
        ));
    }

    public function copyAction() {
        $success = false;
        $sourceId = intval($this->_getParam("sourceId"));
        $source = Document::getById($sourceId);
        $session = new Zend_Session_Namespace("pimcore_copy");
        
        $targetId = intval($this->_getParam("targetId"));
        if($this->_getParam("targetParentId")) {
            $sourceParent = Document::getById($this->_getParam("sourceParentId"));

            // this is because the key can get the prefix "_copy" if the target does already exists
            if($session->{$this->_getParam("transactionId")}["parentId"]) {
                $targetParent = Document::getById($session->{$this->_getParam("transactionId")}["parentId"]);
            } else {
                $targetParent = Document::getById($this->_getParam("targetParentId"));
            }


            $targetPath = preg_replace("@^".$sourceParent->getFullPath()."@", $targetParent."/", $source->getPath());
            $target = Document::getByPath($targetPath);
        } else {
            $target = Document::getById($targetId);
        }

        if($target instanceof Document) {
            $target->getPermissionsForUser($this->getUser());
            if ($target->isAllowed("create")) {
                if ($source != null) {
                    if ($this->_getParam("type") == "child") {
                        $newDocument = $this->_documentService->copyAsChild($target, $source);
                        $session->{$this->_getParam("transactionId")}["idMapping"][(int) $source->getId()] = (int) $newDocument->getId();

                        // this is because the key can get the prefix "_copy" if the target does already exists
                        if($this->_getParam("saveParentId")) {
                            $session->{$this->_getParam("transactionId")}["parentId"] = $newDocument->getId();
                        }
                    }
                    else if ($this->_getParam("type") == "replace") {
                        $this->_documentService->copyContents($target, $source);
                    }

                    $success = true;
                }
                else {
                    Logger::error("prevended copy/paste because document with same path+key already exists in this location");
                }
            }  else {
                Logger::error("could not execute copy/paste because of missing permissions on target [ ".$targetId." ]");
                $this->_helper->json(array("success" => false, "message" => "missing_permission"));
            }
        }

        $this->_helper->json(array("success" => $success));
    }



    

    public function diffVersionsAction() {

        include_once 'DaisyDiff/HTMLDiff.php';
        include_once 'simple_html_dom.php';

        $versionFrom = Version::getById($this->_getParam("from"));
        $versionTo = Version::getById($this->_getParam("to"));

        $docFrom = $versionFrom->loadData();
        $docTo = $versionTo->loadData();

        // unlock the current session to access the version files
        session_write_close();

        $fromSource = file_get_html($this->getRequest()->getScheme() . "://" . $_SERVER["HTTP_HOST"] . $docFrom->getPath() . $docFrom->getKey() . "?pimcore_version=" . $this->_getParam("from") . "&pimcore_admin_sid=" . $_COOKIE["pimcore_admin_sid"]);
        $toSource = file_get_html($this->getRequest()->getScheme() . "://" . $_SERVER["HTTP_HOST"] . $docTo->getPath() . $docTo->getKey() . "?pimcore_version=" . $this->_getParam("to") . "&pimcore_admin_sid=" . $_COOKIE["pimcore_admin_sid"]);

        if ($docFrom instanceof Document_Page) {
            $from = $fromSource->find("body", 0);
            $to = $toSource->find("body", 0);
        }
        else {
            $from = $fromSource;
            $to = $toSource;
        }

        $diff = new HTMLDiffer();
        $text = $diff->htmlDiff($from, $to);


        if ($docFrom instanceof Document_Page) {
            $fromSource->find("head", 0)->innertext = $fromSource->find("head", 0)->innertext . '<link rel="stylesheet" type="text/css" href="/pimcore/static/css/daisydiff.css" />';
            $fromSource->find("body", 0)->innertext = $text;

            echo $fromSource;
        }
        else {
            echo '<link rel="stylesheet" type="text/css" href="/pimcore/static/css/daisydiff.css" />';
            echo $text;
        }


        $this->removeViewRenderer();
    }


    protected function getTreeNodeConfig($childDocument) {

        $tmpDocument = array(
            "id" => $childDocument->getId(),
            "text" => $childDocument->getKey(),
            "type" => $childDocument->getType(),
            "path" => $childDocument->getFullPath(),
            "basePath" => $childDocument->getPath(),
            "locked" => $childDocument->isLocked(),
            "lockOwner" => $childDocument->getLocked() ? true : false,
            "published" => $childDocument->isPublished(),
            "elementType" => "document",
            "leaf" => true,
            "permissions" => array(
                "remove" => $childDocument->isAllowed("delete"),
                "settings" => $childDocument->isAllowed("settings"),
                "rename" => $childDocument->isAllowed("rename"),
                "publish" => $childDocument->isAllowed("publish")
            )
        );

        // add icon
        $tmpDocument["iconCls"] = "pimcore_icon_" . $childDocument->getType();

        // set type specific settings
        if ($childDocument->getType() == "page") {
            $tmpDocument["leaf"] = false;
            $tmpDocument["expanded"] = $childDocument->hasNoChilds();
            $tmpDocument["permissions"]["create"] = $childDocument->isAllowed("create");
            $tmpDocument["iconCls"] = "pimcore_icon_page";

            // test for a site
            try {
                $site = Site::getByRootId($childDocument->getId());
                $tmpDocument["iconCls"] = "pimcore_icon_site";
                $tmpDocument["site"] = $site;
            }
            catch (Exception $e) {
            }
        }
        else if ($childDocument->getType() == "folder" || $childDocument->getType() == "link" || $childDocument->getType() == "hardlink") {
            $tmpDocument["leaf"] = false;
            $tmpDocument["expanded"] = $childDocument->hasNoChilds();

            if ($childDocument->hasNoChilds() && $childDocument->getType() == "folder") {
                $tmpDocument["iconCls"] = "pimcore_icon_folder";
            }
            $tmpDocument["permissions"]["create"] = $childDocument->isAllowed("create");
        }

        $tmpDocument["qtipCfg"] = array(
            "title" => "ID: " . $childDocument->getId(),
            "text" => "Type: " . $childDocument->getType()
        );

        $tmpDocument["cls"] = "";
        
        if (!$childDocument->isPublished()) {
            $tmpDocument["cls"] .= "pimcore_unpublished ";
        }
        
        if($childDocument->isLocked()) {
            $tmpDocument["cls"] .= "pimcore_treenode_locked ";
        }
        if($childDocument->getLocked()) {
            $tmpDocument["cls"] .= "pimcore_treenode_lockOwner ";
        }

        return $tmpDocument;
    }

    /**
     * @param  User $user
     * @param  Document $childDocument
     * @param  Document $parentDocument
     * @param boolean $expanded
     * @return
     */
    protected function getTreeNodePermissionConfig($user, $childDocument, $parentDocument, $expanded) {

        $userGroup = $user->getParent();
        if ($userGroup instanceof User) {
            $childDocument->getPermissionsForUser($userGroup);

            $lock_list = $childDocument->isAllowed("list");
            $lock_view = $childDocument->isAllowed("view");
            $lock_save = $childDocument->isAllowed("save");
            $lock_publish = $childDocument->isAllowed("publish");
            $lock_unpublish = $childDocument->isAllowed("unpublish");
            $lock_delete = $childDocument->isAllowed("delete");
            $lock_rename = $childDocument->isAllowed("rename");
            $lock_create = $childDocument->isAllowed("create");
            $lock_permissions = $childDocument->isAllowed("permissions");
            $lock_settings = $childDocument->isAllowed("settings");
            $lock_versions = $childDocument->isAllowed("versions");
            $lock_properties = $childDocument->isAllowed("properties");
            $lock_properties = $childDocument->isAllowed("properties");
        }


        if ($parentDocument) {
            $parentDocument->getPermissionsForUser($user);
        }
        $documentPermission = $childDocument->getPermissionsForUser($user);

        $generallyAllowed = $user->isAllowed("documents");
        $parentId = (int) $childDocument->getParentId();
        $parentAllowedList = true;
        if ($parentDocument instanceof Document) {
            $parentAllowedList = $parentDocument->isAllowed("list") and $generallyAllowed;
        }

        $tmpDocument = array(
            "_parent" => $parentId > 0 ? $parentId : null,
            "_id" => (int) $childDocument->getId(),
            "text" => $childDocument->getKey(),
            "type" => $childDocument->getType(),
            "path" => $childDocument->getFullPath(),
            "basePath" => $childDocument->getPath(),
            "elementType" => "document",
            "permissionSet" => $documentPermission->getId() > 0 and $documentPermission->getCid() === $childDocument->getId(),
            "list" => $childDocument->isAllowed("list"),
            "list_editable" => $parentAllowedList and $generallyAllowed and !$lock_list and !$user->isAdmin(),
            "view" => $childDocument->isAllowed("view"),
            "view_editable" => $childDocument->isAllowed("list") and $generallyAllowed and !$lock_view and !$user->isAdmin(),
            "save" => $childDocument->isAllowed("save"),
            "save_editable" => $childDocument->isAllowed("list") and $generallyAllowed and !$lock_save and !$user->isAdmin(),
            "publish" => $childDocument->isAllowed("publish"),
            "publish_editable" => $childDocument->isAllowed("list") and $generallyAllowed and !$lock_publish and !$user->isAdmin(),
            "unpublish" => $childDocument->isAllowed("unpublish"),
            "unpublish_editable" => $childDocument->isAllowed("list") and $generallyAllowed and !$lock_unpublish and !$user->isAdmin(),
            "delete" => $childDocument->isAllowed("delete"),
            "delete_editable" => $childDocument->isAllowed("list") and $generallyAllowed and !$lock_delete and !$user->isAdmin(),
            "rename" => $childDocument->isAllowed("rename"),
            "rename_editable" => $childDocument->isAllowed("list") and $generallyAllowed and !$lock_rename and !$user->isAdmin(),
            "create" => $childDocument->isAllowed("create"),
            "create_editable" => $childDocument->isAllowed("list") and $generallyAllowed and !$lock_create and !$user->isAdmin(),
            "permissions" => $childDocument->isAllowed("permissions"),
            "permissions_editable" => $childDocument->isAllowed("list") and $generallyAllowed and !$lock_permissions and !$user->isAdmin(),
            "settings" => $childDocument->isAllowed("settings"),
            "settings_editable" => $childDocument->isAllowed("list") and $generallyAllowed and !$lock_settings and !$user->isAdmin(),
            "versions" => $childDocument->isAllowed("versions"),
            "versions_editable" => $childDocument->isAllowed("list") and $generallyAllowed and !$lock_versions and !$user->isAdmin(),
            "properties" => $childDocument->isAllowed("properties"),
            "properties_editable" => $childDocument->isAllowed("list") and $generallyAllowed and !$lock_properties and !$user->isAdmin()

        );

        $tmpDocument["expanded"] = $expanded;
        $tmpDocument["iconCls"] = "pimcore_icon_" . $childDocument->getType();

        // set type specific settings
        if ($childDocument->getType() == "page") {
            $tmpDocument["_is_leaf"] = $childDocument->hasNoChilds();
            $tmpDocument["iconCls"] = "pimcore_icon_page";

            // test for a site
            try {
                $site = Site::getByRootId($childDocument->getId());
                $tmpDocument["iconCls"] = "pimcore_icon_site";
                $tmpDocument["site"] = $site;
            }
            catch (Exception $e) {
            }
        }
        else if ($childDocument->getType() == "folder") {

            $tmpDocument["_is_leaf"] = $childDocument->hasNoChilds();

            if ($childDocument->hasNoChilds()) {
                $tmpDocument["iconCls"] = "pimcore_icon_folder";
            }

        } else if ($childDocument->getType() == "link") {

            $tmpDocument["_is_leaf"] = $childDocument->hasNoChilds();

            if ($childDocument->hasNoChilds()) {
                $tmpDocument["iconCls"] = "pimcore_icon_link";
            }
        }
        else {
            $tmpDocument["leaf"] = true;
            $tmpDocument["_is_leaf"] = true;
        }

        if (!$childDocument->isPublished()) {
            $tmpDocument["cls"] = "pimcore_unpublished";
        }

        return $tmpDocument;
    }


    public function getIdForPathAction() {

        if ($doc = Document::getByPath($this->_getParam("path"))) {
            $this->_helper->json(array(
                "id" => $doc->getId(),
                "type" => $doc->getType()
            ));
        }
        else {
            $this->_helper->json(false);
        }

        $this->removeViewRenderer();
    }

    public function getVersionsAction() {
        if ($this->_getParam("id")) {
            $doc = Document::getById($this->_getParam("id"));
            $versions = $doc->getVersions();

            $this->_helper->json(array("versions" => $versions));
        }
    }


}
