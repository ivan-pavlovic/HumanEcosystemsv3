<?php
namespace App\Controller;


require_once ('../vendor/emotions/stopwords.php');

use App\Controller\AppController;
use Cake\Event\Event;
use Cake\ORM\TableRegistry;
use App\Model\Table\ResearchesTable;
use App\Model\Entity\Research;
use App\Model\Table\SubjectsTable;
use App\Model\Entity\Subject;
use App\Model\Table\RelationsTable;
use App\Model\Entity\Relation;
use App\Model\Table\ContentsTable;
use App\Model\Entity\Content;
use App\Model\Table\EmotionsTable;
use App\Model\Entity\Emotion;
use App\Model\Table\EmotionTypesTable;
use App\Model\Entity\EmotionType;
use Cake\Controller\Component\RequestHandlerComponent;

class ApiController extends AppController
{

	public function beforeFilter(Event $event){
		parent::beforeFilter($event);
		$this->Auth->allow( ['getRelations','getWordNetwork' , 'getEmotions', 'getTimeline', 'getEmotionsTimeline', 'getWordCloud' , 'getEnergyComfortDistribution', 'getGeoPoints', 'getGeoEmotionPoints','getHashtagNetwork', 'getHashtagCloud', 'getSentiment','getContentMatch' ] );
		$this->RequestHandler->renderAs($this, 'json');
	    $this->response->type('application/json');
	    $this->set('_serialize', true);
	}

	public function initialize()
	{
		parent::initialize();
	    $this->loadComponent('RequestHandler');
	}

	public function getRelations(){
		$nodes = array();
		$links = array();

		if(!is_null($this->request->query('researches'))  && $this->request->query('researches')!="" ){

			$researcharray = explode(",", $this->request->query('researches')  );

			// calc relations

			// do nodes
			$subjects = TableRegistry::get('Subjects');

			$q1 = null;

			if( null!==$this->request->query('limit')){
				$q1 = $subjects->find('all')
					->where( ['research_id IN' => $researcharray ] )
	    			->select(['id', 'screen_name' , 'profile_url'])
	    			->order(['id' => 'DESC'])
	    			->limit(  $this->request->query('limit')  );
			} else {
				$q1 = $subjects->find('all')
					->where( ['research_id IN' => $researcharray ] )
					->order(['id' => 'DESC'])
	    			->select(['id', 'screen_name' , 'profile_url']);
			}

				

	    	foreach($q1 as $s){
	    		$o = new \stdClass();
	    		$o->id = $s->id;
	    		$o->nick = $s->screen_name;
	    		$o->pu = $s->profile_url;
	    		$nodes[] = $o;
	    	}
			// do nodes end


			// do edges
			$relations = TableRegistry::get('Relations');

			$q1 = $relations->find('all')
					->where( ['research_id IN' => $researcharray ] )
	    			->select(['id', 'subject_1_id' , 'subject_2_id' , 'c']);

	    	foreach($q1 as $s){
	    		$o = new \stdClass();
	    		$o->id = $s->id;
	    		$o->source = $s->subject_1_id;
	    		$o->target = $s->subject_2_id;
	    		$o->weight = intval( $s->c );
	    		$links[] = $o;
	    	}

	    	//replace source,target with names, and fill in missing ones

	    	for($i = count($links)-1; $i>=0; $i--){
	    		$foundsource = false;
	    		$foundtarget = false;
	    		$nick = "";
	    		for($j = 0; $j<count($nodes) && (!$foundsource || !$foundtarget); $j++){
	    			if($nodes[$j]->id==$links[$i]->source){
	    				$foundsource = true;
	    				$links[$i]->source = $nodes[$j]->nick;
	    			}
	    			if($nodes[$j]->id==$links[$i]->target){
	    				$foundtarget = true;
	    				$links[$i]->target = $nodes[$j]->nick;
	    			}
	    		}
	    		
	    		if(!$foundsource || !$foundtarget){
					array_splice($links, $i, 1);	    				
    			}

	    	}

	    	//replace source,target with names, and fill in missing ones - end

			// do edges end

		}

		$this->set(compact('nodes', 'links'));
		$this->set('_serialize', ['nodes', 'links']);

	}

	function getWordNetwork(){

		$stopwords = new \StopWords();

		$nodes = array();
		$links = array();

		$results = array();
		$resultsrel = array();

		if(!is_null($this->request->query('researches'))  && $this->request->query('researches')!="" ){

			$researcharray = explode(",", $this->request->query('researches')  );

			$contents = TableRegistry::get('Contents');

			$q1 = null;

			$conditions = array();
			$conditions['research_id IN'] = $researcharray;
			if( null!==$this->request->query('language') &&  $this->request->query('language')!="XXX" ){
				$conditions['language'] = $this->request->query('language');
			}

			if( null!==$this->request->query('limit')){
				$q1 = $contents->find('all')
					->where( $conditions )
	    			->select(['content','energy','comfort'])
	    			->order(['id' => 'DESC'])
	    			->limit(  $this->request->query('limit')  );
			} else {
				$q1 = $contents->find('all')
					->where( $conditions )
	    			->select(['content','energy','comfort']);
			}

			$renergy = array();
			$rcomfort = array();


			$idid = 1;

			foreach($q1 as $c){

				$val = $c->content;

				$val = preg_replace('/#\S+ */', '', $val);

				$regex = "@(https?://([-\w\.]+[-\w])+(:\d+)?(/([\w/_\.#-]*(\?\S+)?[^\.\s])?).*$)@";
				$val = preg_replace($regex, ' ', $val);
				$val = preg_replace("/[^[:alnum:][:space:]]/ui", ' ', $val);

				$val = strtoupper($val);

				$val = str_replace("HTTPS", ' ', $val); // remove https
				$val = str_replace("HTTP", ' ', $val); // remove http

				$val = str_replace("\t", ' ', $val); // remove tabs
				$val = str_replace("\n", ' ', $val); // remove new lines
				$val = str_replace("\r", ' ', $val); // remove carriage returns

				$val = strtolower($val);
				$val = preg_replace("#[[:punct:]]#", " ", $val);
				$val = preg_replace("/[^A-Za-z]/", ' ', $val);

				for($i = 0; $i<count($stopwords->stopwords); $i++){
					$val = preg_replace('/\b' . $stopwords->stopwords[$i] . '\b/u', ' ', $val);
				}

				$words = explode(" ", $val);

				$resultcontent = array();

				for($i=0; $i<count($words); $i++){

					if(trim($words[$i])!="" && strlen($words[$i])>3 ){
						if(isset($results[$words[$i]])){
							$results[$words[$i]] = $results[$words[$i]] + 1;
						} else {
							$results[$words[$i]] = 1;
						}
						if(isset($resultcontent[$words[$i]])){
							$resultcontent[$words[$i]] = $resultcontent[$words[$i]] + 1;
						} else {
							$resultcontent[$words[$i]] = 1;
						}
						if(!isset($renergy[$words[$i]])){
							$renergy[$words[$i]] = 0;
						}
						if($c->energy!=0){ $renergy[$words[$i]] = ($renergy[$words[$i]]+$c->energy)/2; }
						if(!isset($rcomfort[$words[$i]])){
							$rcomfort[$words[$i]] = 0;
						}
						if($c->comfort!=0){ $rcomfort[$words[$i]] = ($rcomfort[$words[$i]]+$c->comfort)/2; }
					}
				}

				$ii = 0;
				foreach ($resultcontent as $key1 => $value1) {
					$jj = 0;
					foreach ($resultcontent as $key2 => $value2) {
						if($key1!=$key2){
							$oo = new \stdClass();
							$oo->id = $idid;
							$oo->source = $key1;
							$oo->target = $key2;
							$oo->sourceid = $ii;
							$oo->targetid = $jj;
							$oo->weight = $value1 + $value2;
							$links[] = $oo;
							$idid++;
						}
						$jj++;
					}
					$ii++;			
				}

			}
			//foreach

			foreach ($results as $key => $value) {
				$o = new \stdClass();
				$o->id = $key;
				$o->word = $key;
				$o->weight = $value;
				$o->energy = $renergy[$key];
				$o->comfort = $rcomfort[$key];
				$nodes[] = $o;
			}

			for($i = 0; $i<count($links); $i++){
				$found1 = false;
				$found2 = false;
				for($j=0; $j<count($nodes)&&!$found1&&!$found2; $j++){
					if($nodes[$j]->word==$links[$i]->source){
						$links[$i]->sourceid = $j;
						$found1 = true;
					}
					if($nodes[$j]->word==$links[$i]->target){
						$links[$i]->targetid = $j;
						$found2 = true;
					}
				}
			}

		}

		$this->set(compact('nodes', 'links'));
		$this->set('_serialize', ['nodes', 'links']);

	}




	function getEmotions(){

		$results = array();
		$et = array();

		if(!is_null($this->request->query('researches'))  && $this->request->query('researches')!="" ){

			$researcharray = explode(",", $this->request->query('researches')  );

			$emotions = TableRegistry::get('Emotions');
			$emotiontypes = TableRegistry::get('EmotionTypes');

			$q0 = $emotiontypes->find('all');
			foreach($q0 as $e){
				$o = new \stdClass();
				$o->label = $e->label;
				$o->id = $e->id;
				$o->energy = $e->energy;
				$o->comfort = $e->comfort;
				$et[] = $o;
			}

			$q1 = null;

			$conditions = array();
			$conditions['research_id IN'] = $researcharray;

			$q1 = $emotions->find('all');

			$q1->select([
				    'value' => $q1->func()->count('emotion_type_id'),
				    'emotion_id' => 'emotion_type_id'
				])
				->where( $conditions )
				->group('emotion_id');

			foreach($q1 as $c){
				$found = false;
				for($i=0; $i<count($et) && !$found;$i++){
					if($et[$i]->id==$c->emotion_id){
						$found = true;
						$o = new \stdClass();
						$o->label = $et[$i]->label;
						$o->value = $c->value;
						$results[] = $o;
					}
				}
			}
			//foreach

		}

		$this->set(compact('results'));
		$this->set('_serialize', ['results']);

	}




	function getTimeline(){

		$results = array();

		if(!is_null($this->request->query('researches'))  && $this->request->query('researches')!="" ){

			$researcharray = explode(",", $this->request->query('researches')  );

			$contents = TableRegistry::get('Contents');

			$q1 = null;

			$conditions = array();
			$conditions['research_id IN'] = $researcharray;

			$q1 = $contents->find('all');

			$q1->select([
				    'd' => 'DAY(created_at)',//$q1->func()->count('emotion_type_id'),
				    'm' => 'MONTH(created_at)',//$q1->func()->count('emotion_type_id'),
				    'y' => 'YEAR(created_at)',//$q1->func()->count('emotion_type_id'),
				    'value' => $q1->func()->count('id')
				])
				->where( $conditions )
				->group(
					[
						'YEAR(created_at)', 'MONTH(created_at)', 'DAY(created_at)'
					]
				)
				->order(['created_at' => 'DESC']);

			foreach($q1 as $c){
				$o = new \stdClass();
				$o->date =  ($c->d<10?"0":"") . $c->d . "-" . ($c->m<10?"0":"") . $c->m . "-" . ($c->y<100?"19":"") . $c->y ;
				$o->close = $c->value;
				$results[] = $o;
			}
			//foreach

		}

		$this->set(compact('results'));
		$this->set('_serialize', ['results']);

	}



	function getEmotionsTimeline(){

		$results = array();
		$et = array();

		if(!is_null($this->request->query('researches'))  && $this->request->query('researches')!="" ){

			$emotions_types = TableRegistry::get('EmotionTypes');
			$q0 = $emotions_types->find('all');
			foreach($q0 as $e){
				$o = new \stdClass();
				$o->label = $e->label;
				$o->id = $e->id;
				$o->energy = $e->energy;
				$o->comfort = $e->comfort;
				$et[] = $o;
			}

			$researcharray = explode(",", $this->request->query('researches')  );

			$emotions = TableRegistry::get('Emotions');

			$q1 = null;

			$conditions = array();
			$conditions['Emotions.research_id IN'] = $researcharray;

			$q1 = $emotions->find('all')->contain(['Contents']);

			$q1->select([
					'emotion_type_id' => 'Emotions.emotion_type_id',
				    'd' => 'DAY(Contents.created_at)',//$q1->func()->count('emotion_type_id'),
				    'm' => 'MONTH(Contents.created_at)',//$q1->func()->count('emotion_type_id'),
				    'y' => 'YEAR(Contents.created_at)',//$q1->func()->count('emotion_type_id'),
				    'value' => $q1->func()->count('Emotions.id')
				])
				->where( $conditions )
				->group(
					[
						'YEAR(Contents.created_at)', 'MONTH(Contents.created_at)', 'DAY(Contents.created_at)', 'Emotions.emotion_type_id'
					]
				)
				->order(['Contents.created_at' => 'DESC']);

			foreach($q1 as $c){
				$o = new \stdClass();
				$o->emotion_type_id = $c->emotion_type_id;

				$o->emotion_label = "";

				$found = false;
				for($i=0; $i<count($et) && !$found; $i++){
					if($o->emotion_type_id==$et[$i]->id){
						$found = true;
						$o->emotion_label = $et[$i]->label;
					}
				}

				$o->date =  ($c->d<10?"0":"") . $c->d . "-" . ($c->m<10?"0":"") . $c->m . "-" . ($c->y<100?"19":"") . $c->y ;
				$o->close = $c->value;
				$results[] = $o;
			}
			//foreach

		}

		$restot = array();
		foreach ($results as $o) {
			$lab = $o->emotion_label;
			unset($o->emotion_type_id);
			unset($o->emotion_label);
			$restot[$lab][] = $o;
		}

		$results = $restot;

		$this->set(compact('results'));
		$this->set('_serialize', ['results']);

	}



	function getWordCloud(){

		$stopwords = new \StopWords();

		$results = array();

		if(!is_null($this->request->query('researches'))  && $this->request->query('researches')!="" ){

			$researcharray = explode(",", $this->request->query('researches')  );

			$contents = TableRegistry::get('Contents');

			$q1 = null;

			$conditions = array();
			$conditions['research_id IN'] = $researcharray;
			if( null!==$this->request->query('language') &&  $this->request->query('language')!="XXX" ){
				$conditions['language'] = $this->request->query('language');
			}

			if( null!==$this->request->query('limit')){
				$q1 = $contents->find('all')
					->where( $conditions )
	    			->select(['content'])
	    			->order(['id' => 'DESC'])
	    			->limit(  $this->request->query('limit')  );
			} else {
				$q1 = $contents->find('all')
					->where( $conditions )
	    			->select(['content']);
			}

			foreach($q1 as $c){

				$val = $c->content;

				$regex = "@(https?://([-\w\.]+[-\w])+(:\d+)?(/([\w/_\.#-]*(\?\S+)?[^\.\s])?).*$)@";
				$val = preg_replace($regex, ' ', $val);
				$val = preg_replace("/[^[:alnum:][:space:]]/ui", ' ', $val);

				$val = strtoupper($val);

				$val = str_replace("HTTPS", ' ', $val); // remove https
				$val = str_replace("HTTP", ' ', $val); // remove http

				$val = str_replace("\t", ' ', $val); // remove tabs
				$val = str_replace("\n", ' ', $val); // remove new lines
				$val = str_replace("\r", ' ', $val); // remove carriage returns

				$val = strtolower($val);
				$val = preg_replace("#[[:punct:]]#", " ", $val);
				$val = preg_replace("/[^A-Za-z]/", ' ', $val);

				for($i = 0; $i<count($stopwords->stopwords); $i++){
					$val = preg_replace('/\b' . $stopwords->stopwords[$i] . '\b/u', ' ', $val);
				}

				$words = explode(" ", $val);

				$resultcontent = array();

				for($i=0; $i<count($words); $i++){

					if(trim($words[$i])!="" && strlen($words[$i])>3 ){
						if(isset($results[$words[$i]])){
							$results[$words[$i]] = $results[$words[$i]] + 1;
						} else {
							$results[$words[$i]] = 1;
						}
						if(isset($resultcontent[$words[$i]])){
							$resultcontent[$words[$i]] = $resultcontent[$words[$i]] + 1;
						} else {
							$resultcontent[$words[$i]] = 1;
						}
					}
				}


			}
			//foreach

		}


		$children = array();
		foreach ($results as $k => $r) {
			$c = new \stdClass();
			$c->name = $k;
			$c->value = $r;
			$children[] = $c;
		}

		$this->set(compact('children'));
		$this->set('_serialize', ['children']);

	}





	function getEnergyComfortDistribution(){

		$results = array();

		if(!is_null($this->request->query('researches'))  && $this->request->query('researches')!="" ){

			$researcharray = explode(",", $this->request->query('researches')  );

			$contents = TableRegistry::get('Contents');

			$q1 = null;

			$conditions = array();
			$conditions['research_id IN'] = $researcharray;
			if( null!==$this->request->query('language') &&  $this->request->query('language')!="XXX" ){
				$conditions['language'] = $this->request->query('language');
			}

			if( null!==$this->request->query('limit')){
				$q1 = $contents->find('all');

	    		$q1->select([
	    				'comfort' => 'comfort', 
	    				'energy' => 'energy',
	    				'c' => $q1->func()->count('id'),
	    			])
	    			->where( $conditions )
	    			->order(['id' => 'DESC'])
	    			->group(
						[
							'comfort', 'energy'
						]
					)
	    			->limit(  $this->request->query('limit')  );
			} else {
				$q1 = $contents->find('all');
	    		$q1->select([
	    				'comfort' => 'comfort', 
	    				'energy' => 'energy',
	    				'c' => $q1->func()->count('id')
	    			])
	    			->where( $conditions )
	    			->group(
						[
							'comfort', 'energy'
						]
					)
	    			;
			}

			foreach($q1 as $c){

				if($c->comfort!=0 || $c->energy!=0){

					$o = new \stdClass();
					$o->c = $c->c;
					$o->comfort = $c->comfort;
					$o->energy = $c->energy;

					$results[] = $o;	

				}
				
			}
			//foreach

		}

		$this->set(compact('results'));
		$this->set('_serialize', ['results']);

	}



	function getGeoPoints(){

		$results = array();

		if(!is_null($this->request->query('researches'))  && $this->request->query('researches')!="" ){

			$researcharray = explode(",", $this->request->query('researches')  );

			$contents = TableRegistry::get('Contents');

			$q1 = null;

			$conditions = array();
			$conditions['research_id IN'] = $researcharray;
			if( null!==$this->request->query('language') &&  $this->request->query('language')!="XXX" ){
				$conditions['language'] = $this->request->query('language');
			}

			if( null!==$this->request->query('limit')){
				$q1 = $contents->find('all');

	    		$q1->select([
	    				'lat' => 'lat', 
	    				'lng' => 'lng',
	    				'c' => $q1->func()->count('id'),
	    			])
	    			->where( $conditions )
	    			->order(['id' => 'DESC'])
	    			->group(
						[
							'lat', 'lng'
						]
					)
	    			->limit(  $this->request->query('limit')  );
			} else {
				$q1 = $contents->find('all');
	    		$q1->select([
	    				'lat' => 'lat', 
	    				'lng' => 'lng',
	    				'c' => $q1->func()->count('id')
	    			])
	    			->where( $conditions )
	    			->group(
						[
							'lat', 'lng'
						]
					)
	    			;
			}

			foreach($q1 as $c){

				if( ($c->lat!=0 || $c->lng!=0) && ($c->lat!=-999 || $c->lng!=-999)  ){

					$o = new \stdClass();
					$o->c = $c->c;
					$o->lat = $c->lat;
					$o->lng = $c->lng;

					$results[] = $o;	
					
				}
				
			}
			//foreach

		}

		$this->set(compact('results'));
		$this->set('_serialize', ['results']);

	}


	function getGeoEmotionPoints(){

		$results = array();

		if(!is_null($this->request->query('researches'))  && $this->request->query('researches')!="" ){

			$researcharray = explode(",", $this->request->query('researches')  );

			$emotions = TableRegistry::get('Emotions');

			$emotiontypes = TableRegistry::get('EmotionTypes');
			$q0 = $emotiontypes->find('all');
			$et = array();
			foreach($q0 as $e){
				$o = new \stdClass();
				$o->emotion_type_id = $e->id;
				$o->label = $e->label;
				$et[] = $o;
			}

			$q1 = null;

			$conditions = array();
			$conditions['Emotions.research_id IN'] = $researcharray;
			if( null!==$this->request->query('language') &&  $this->request->query('language')!="XXX" ){
				$conditions['Contents.language'] = $this->request->query('language');
			}

			if( null!==$this->request->query('limit')){
				$q1 = $emotions->find('all')->contain(['Contents']);

	    		$q1->select([
	    				'emotion_type_id' => 'Emotions.emotion_type_id',
	    				'lat' => 'Contents.lat', 
	    				'lng' => 'Contents.lng',
	    				'c' => $q1->func()->count('Emotions.id'),
	    			])
	    			->where( $conditions )
	    			->order(['Contents.id' => 'DESC'])
	    			->group(
						[
							'Contents.lat', 'Contents.lng'
						]
					)
	    			->limit(  $this->request->query('limit')  );
			} else {
				$q1 = $emotions->find('all')->contain(['Contents']);
	    		$q1->select([
	    				'emotion_type_id' => 'Emotions.emotion_type_id',
	    				'lat' => 'Contents.lat', 
	    				'lng' => 'Contents.lng',
	    				'c' => $q1->func()->count('Emotions.id')
	    			])
	    			->where( $conditions )
	    			->group(
						[
							'Contents.lat', 'Contents.lng'
						]
					)
	    			;
			}

			foreach($q1 as $c){

				if( ($c->lat!=0 || $c->lng!=0) && ($c->lat!=-999 || $c->lng!=-999)  ){

					$o = new \stdClass();
					$o->emotion_type_id = $c->emotion_type_id;
					$o->c = $c->c;
					$o->lat = $c->lat;
					$o->lng = $c->lng;
					$o->label = "";

					$found = false;
					for($i = 0; $i<count($et) && !$found; $i++){
						if($et[$i]->emotion_type_id==$o->emotion_type_id){
							$found = true;
							$o->label = $et[$i]->label;
						}
					}

					$results[] = $o;	
					
				}
				
			}
			//foreach

		}

		$this->set(compact('results'));
		$this->set('_serialize', ['results']);

	}


	function getHashtagNetwork(){

		$nodes = array();
		$links = array();

		$results = array();
		$resultsrel = array();

		if(!is_null($this->request->query('researches'))  && $this->request->query('researches')!="" ){

			$researcharray = explode(",", $this->request->query('researches')  );

			$contents = TableRegistry::get('Contents');

			$q1 = $contents
			    ->find('all');
			
			if( null!==$this->request->query('limit')){
				$q1->contain(['Entities'])
			    ->matching('Entities')
			    ->where([
			        'Contents.research_id IN' => $researcharray,
			        'Entities.entity_type_id' => 1
			    ])
			    ->order(['Contents.id' => 'DESC'])
			    ->limit(  $this->request->query('limit')  );
			}else{
			    $q1->contain(['Entities'])
			    ->matching('Entities')
			    ->where([
			        'Contents.research_id IN' => $researcharray,
			        'Entities.entity_type_id' => 1
			    ]);
			}
			
			foreach($q1 as $c){

				$contentres = array();

				foreach($c->entities as $e){

					if($e->entity_type_id==1){
						$o = new \stdClass();
						$o->id = $e->id;
						$o->label = $e->entity;
						$o->weight = 1;

						$found = false;

						for($i = 0; $i<count($contentres)&&!$found;$i++){
							if($contentres[$i]->id==$o->id){
								$found = true;
								$contentres[$i]->weight = $contentres[$i]->weight + 1;
							}
						}

						if(!$found){
							$contentres[] = $o;
						}

					}					

				}

				foreach($contentres as $co){
					$found = false;
					for($i = 0; $i<count($nodes)&&!$found;$i++){
						if($nodes[$i]->id==$co->id){
							$found = true;
							$nodes[$i]->weight = $nodes[$i]->weight + 1;
						}
					}
					if(!$found){
						$nodes[] = $co;
					}

				}

				for($i=0; $i<count($contentres); $i++){
					for($j=$i+1; $j<count($contentres); $j++){
						$found = false;
						for($k=0; $k<count($links)&&!$found; $k++){
							if( $links[$k]->source==$contentres[$i]->label && $links[$k]->target==$contentres[$j]->label ){
								$found = true;
								$links[$k]->weight = $links[$k]->weight + 1;
							}
						}
						if(!$found){
							$o = new \stdClass();
							$o->source = $contentres[$i]->label;
							$o->target = $contentres[$j]->label;
							$o->weight = 1;
							$links[] = $o;
						}
					}
				}

			}
			//foreach
			
		}

		$this->set(compact('nodes', 'links'));
		$this->set('_serialize', ['nodes', 'links']);

	}



	function getHashtagCloud(){

		$results = array();

		if(!is_null($this->request->query('researches'))  && $this->request->query('researches')!="" ){

			$researcharray = explode(",", $this->request->query('researches')  );

			$ces = TableRegistry::get('ContentsEntities');

			$q1 = $ces
			    ->find('all');

			if( null!==$this->request->query('limit')){
				$q1
			    ->contain(['Entities'])
			    ->matching('Entities')
			    ->where([
			        'ContentsEntities.research_id IN' => $researcharray,
			        'Entities.entity_type_id' => 1
			    ])
			    ->order(['ContentsEntities.id' => 'DESC'])
			    ->limit(  $this->request->query('limit')  );
			} else {
			    $q1
			    ->contain(['Entities'])
			    ->matching('Entities')
			    ->where([
			        'ContentsEntities.research_id IN' => $researcharray,
			        'Entities.entity_type_id' => 1
			    ]);
			}

			foreach($q1 as $ce){
				$label = $ce->entity->entity;
				if(isset($results[$label])){
					$results[$label] = $results[$label] +1;
				} else {
					$results[$label] = 1;
				}
			}

			
		}


		$children = array();
		foreach ($results as $k => $r) {
			$c = new \stdClass();
			$c->name = $k;
			$c->value = $r;
			$children[] = $c;
		}

		$this->set(compact('children'));
		$this->set('_serialize', ['children']);

	}


	function getSentiment(){

		$results = array();

		$positive = 0;
		$negative = 0;
		$neutral = 0;

		$negative_threshold = 20;

		if(!is_null($this->request->query('researches'))  && $this->request->query('researches')!="" ){

			$researcharray = explode(",", $this->request->query('researches')  );

			$ces = TableRegistry::get('Contents');

			$q1 = $ces
			    ->find('all');

			$conditions = ['research_id IN' => $researcharray];

			if( null!==$this->request->query('language')){
				$conditions = ['language' => $this->request->query('language') ];
			}

			if( null!==$this->request->query('limit')){
				$q1
			    ->where($conditions)
			    ->order(['id' => 'DESC'])
			    ->limit(  $this->request->query('limit')  );
			} else {
			    $q1
			    ->where($conditions);
			}




			foreach($q1 as $ce){
				if($ce->comfort > $negative_threshold){
					$positive = $positive + 1;
				} else if($ce->comfort < -$negative_threshold){
					$negative = $negative + 1;
				} else {
					$neutral = $neutral + 1;
				}
			}

			
		}

		$this->set(compact('positive','negative','neutral'));
		$this->set('_serialize', ['positive','negative','neutral']);

	}

	function getContentMatch(){

		$results = array();

		if(!is_null($this->request->query('researches'))  && $this->request->query('researches')!="" ){

			$researcharray = explode(",", $this->request->query('researches')  );



			if(!is_null($this->request->query('q'))  && $this->request->query('q')!="" ){

				$ces = TableRegistry::get('Contents');

				$conditions = [
					'research_id IN' => $researcharray,
					'OR' => [
						['content LIKE' => '% ' . $this->request->query('q') . '%'],
						['content LIKE' => $this->request->query('q') . '%'	],
						['content LIKE' => '% ?' . $this->request->query('q') . '%']
					]
					
				];

				if(!is_null($this->request->query('language'))  && $this->request->query('language')!="" && $this->request->query('language')!="XXX" ){
					$conditions[] = [ "language" => $this->request->query('language') ];
				}

				$q1 = null;

				if(!is_null($this->request->query('limit'))  && $this->request->query('limit')!="" ){
					$q1 = $ces
					    ->find('all')
					    ->select([
					    	'link', 'content', 'created_at', 'lat', 'lng', 'comfort','energy'
					    ])
					    ->where( $conditions )
					    ->limit( $this->request->query('limit') );
				} else {
					$q1 = $ces
					    ->find('all')
					    ->select([
					    	'link', 'content', 'created_at', 'lat', 'lng', 'comfort','energy'
					    ])
					    ->where( $conditions );
				}

				//debug($q1);

				foreach ($q1 as $r) {
					$o = new \stdClass();
					$o->link = $r->link;
					$o->content = $r->content;
					$o->created_at = $r->created_at;
					$o->lat = $r->lat;
					$o->lng = $r->lng;
					$o->comfort = $r->comfort;
					$o->energy = $r->energy;
					$results[] = $o;
				}
			}
		}

		$this->set(compact('results'));
		$this->set('_serialize', ['results']);

	}



}