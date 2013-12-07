<?php

/*
__PocketMine Plugin__
name=PlotPe
description=PlotMe ported
version=1.0
author=wies
class=PlotPe
apiversion=10
*/
		
class PlotPe implements Plugin{
	private $api;
    public $plots;
    public $plotLevelInfo;
    public $comments;
    public $config;
	public static $staticConfig;

	public function __construct(ServerAPI $api, $server = false){
		$this->api = $api;
	}
	
	public function init(){
		$this->path = $this->api->plugin->configPath($this);
		$this->api->console->register("plot", "Plot Commands", array($this, "command"));
		$this->api->ban->cmdWhitelist("plot");
		$this->api->addHandler("player.block.place", array($this, "block"));
		$this->api->addHandler("player.block.break", array($this, "block"));
		$this->api->addHandler("player.block.touch", array($this, "block"));
		$config = new Config($this->path."config.yml", CONFIG_YAML, array(
			'PlotSize' => 32,
			'RoadSize' => 3,
			'PlotFloorBlockId' => 2,
			'PlotFillingBlockId' => 3,
			'CornerBlockId' => 44,
			'RoadBlockId' => 5,
            'Height' => 27,
			'ExpireAfterXDays' => false,
		));
		$this->config = $this->api->plugin->readYAML($this->path . "config.yml");
		self::$staticConfig = $this->config;
        $this->loadFiles();
		
	}

    public function loadFiles(){
        $this->plots = array();
        $this->comments = array();
        $this->totalPlotWorlds = 0;
        $dir = $this->path.'worlds/';
		if(!is_dir($dir)){
			mkdir($dir);
			return false;
		}
		if(($handle = opendir($dir)) === false) return;
        while(($file = readdir($handle)) !== false){
            if(!strpos($file, '.yml')) continue;
            $level = substr($file, 0, -4);
            if(file_exists(DATA_PATH.'worlds/'.$level.'/level.pmf')){
                $this->api->level->loadLevel($level);
            }else{
                continue;
            }
            $config = $this->api->plugin->readYAML($dir.$file);
            if(!(isset($config['plots']) and isset($config['info']) and isset($config['comments']))) continue;
            $this->plots[$level] = $config['plots'];
            $this->plotLevelInfo[$level] = $config['info'];
            $this->comments[$level] = $config['comments'];
            $this->totalPlotWorlds++;
        }
    }

    public function saveFiles(){
        $dir = $this->path.'worlds/';
        foreach($this->plotLevelInfo as $level => $info){
            $data = array();
            $data['info'] = $info;
            $data['plots'] = $this->plots[$level];
            $data['comments'] = $this->comments[$level];
            $this->api->plugin->writeYAML($dir.$level.'yml', $data);
        }
    }
	
	public function command($cmd, $args, $issuer){
		$username = $issuer->iusername;
		$output = '';
		switch($args[0]){
			case 'newworld':
				if(!($issuer instanceof Player)){
                    if(!isset($args[1])) $args[1] = 'PlotPeWorld'.($this->totalPlotWorlds + 1);
					$this->createPlotWorld($args[1]);
				}else{
					$output = "You can only use this command in the console";
				}
				break;
				
			case 'claim':
				$x = $issuer->entity->x;
				$z = $issuer->entity->z;
				$level = $issuer->level->getName();
				$plot = $this->getPlotByPos($x, $z, $level);
				if($plot === false){
					$output = "You need to stand in a plot";
					break;
				}
				if($plot['owner'] !== false){
					$output = "This plot is already claimed by somebody";
					break;
				}
				$this->plots[$plot['id']]['owner'] = $username;
				if($this->plotLevelInfo[$level]['ExpireTime'] != false){
					$next = time() + ($this->plotLevelInfo[$level]['ExpireTime'] * 24 * 60 * 60);
					$this->plots[$level][$plot['id']]['expireDate'] = date('Y-m-d', $next);
				}
				$this->tpToPlot($plot, $issuer);
				$output = 'You are now the owner of this plot with id: '.$plot['id'].' in world: '.$level;
				break;
				
			case 'home':
				if(isset($args[1])){
					if(!is_numeric($args[1])){
						$output = 'Usage /plot home <optional-id>';
						break;
					}
					$id = $args[1] - 1;
				}else{
					$id = 0;
				}
				$plot = $this->getPlotByOwner($username);
				if($plot === false){
					$output = "You don't have a plot, create one with /plot auto or /plot claim";
					break;
				}elseif(!isset($plot[$id])){
					$output = "The id isn't right. You don't have so many plots.";
					break;
				}
				$this->tpToPlot($plot[$id], $issuer);
				$output = 'You have been teleported to your plot with id: '.$plot[$id]['id'].' and in the world: '.$plot[$id]['level'];
				break;
				
			case 'auto':
				foreach($this->plots as $level => $plots){
                    foreach($plots as $id => $plot){
                        if($plot['owner'] === false){
                            $this->plots[$level][$id]['owner'] = $username;
							if($this->plotLevelInfo[$level]['ExpireTime'] != false){
								$next = time() + ($this->plotLevelInfo[$level]['ExpireTime'] * 24 * 60 * 60);
								$this->plots[$level][$id]['expireDate'] = date('Y-m-d', $next);
							}
							$this->tpToPlot($plot, $issuer);
                            $output = 'You auto-claimed a plot with id:'.$plot['id'].' in world:'.$level;
                            break;
                        }
                    }
                }
				$output = 'Their are no available plots anymore';
				break;
				
			case 'list':
				$plots = $this->getPlotsByOwner($username);
				if($plots === false){
					$output = "You don't have a plot, create one with /plot auto or /plot claim";
					break;
				}
				$output = "==========[Your Plots]========== \n";
				foreach($plots as $key => $val){
					$output .= ($key + 1).'. id:'.$val['id'].' world:'.$val['level']."\n";
				}
				break;
				
			case 'info':
				$plot = $this->getPlotByPos($issuer->entity->x, $issuer->entity->z, $issuer->level->getName());
				if($plot === false){
					$output = 'You need to stand in a plot';
					break;
				}
				if(isset($plot['helpers'])){
                    $helpers = implode(',', $plot['helpers']);
                }else{
                    $helpers = 'none';
                }
				$output = "==========[Plot Info]==========\n";
				$output .= 'Id: '.$plot['id']."\n";
                $output .= 'Owner: '.$plot['owner']."\n";
				$output .= 'Helpers: '.$helpers."\n";
				$output .= 'Expire: '.$plot['expireDate'];
				break;
				
				
			case 'addhelper':
				if(!isset($args[1])){
					$output = 'Usage: /plot addhelper <player>';
					break;
				}
				$player = strtolower($args[1]);
				$plot = $this->getPlotByPos($issuer->entity->x, $issuer->entity->z, $issuer->level->getName());
				if($plot === false){
					$output = 'You need to stand in a plot';
					break;
				}
				if($plot['owner'] !== $username){
					$output = "You're not the owner of this plot";
					break;
				}
				$helpers = $plot['helpers'];
				if(in_array($player, $helpers)){
					$output = $player.' was already a helper of this plot';
					break;
				}
				array_push($helpers, $player);
				$this->plots[$plot['level']][$plot['id']]['helpers'] = $helpers;
				$output = $player.' is now a helper of this plot';
				break;
				
			case 'removehelper':
				if(!isset($args[1])){
					$output = 'Usage: /plot removehelper <player>';
					break;
				}
				$player = strtolower($args[1]);
				$plot = $this->getPlotByPos($issuer->entity->x, $issuer->entity->z, $issuer->level->getName());
				if($plot === false){
					$output = 'You need to stand in a plot';
					break;
				}
			    if($plot['owner'] !== $username){
					$output = "You're not the owner of this plot";
					break;
				}
				$helpers = $plot['helpers'];
				$key = array_search($player, $helpers);
				if($key === false){
					$output = $player.' is no helper of your plot';
					break;
				}
				unset($helpers[$key]);
                $this->plots[$plot['level']][$plot['id']]['helpers'] = $helpers;
				$output = $player.' is removed as a helper from this plot';
				break;
			
			case 'clear':
			case 'reset':
				$plot = $this->getPlotByPos($issuer->entity->x, $issuer->entity->z, $issuer->level->getName());
				if($plot === false){
					$output = 'You need to stand in a plot';
					break;
				}
				if($plot['owner'] != $username){
					$output = "You're not the owner of this plot";
					break;
				}
				$this->resetPlot($plot);
				if($args[0] === 'clear'){
					$output = 'Plot cleared!';
				}else{
					$this->plots[$plot['level']][$plot['id']] = array(
						'id' => $plot['id'],
						'level' => $plot['level'],
						'owner' => false,
						'helpers' => array(),
						'expireDate' => false,
					);
					$this->comments[$plot['level']][$plot['id']] = array();
					$output = 'Plot deleted';
				}
				break;

			case 'comment':
				if(!isset($args[1])){
					$output = 'Usage: /plot command <message>';
					break;
				}
				array_shift($args);
				$message = implode(' ', $args);
				$plot = $this->getPlotByPos($issuer->entity->x, $issuer->entity->z, $issuer->level->getName());
				if($plot === false){
					$output = 'You need to stand in a plot';
					break;
				}
				$this->comments[$plot['level']][$plot['id']][] = array('message' => $message, 'writer' => $username);
				$output = 'Comment added';
				break;
				
			case 'comments':
				$plot = $this->getPlotByPos($issuer->entity->x, $issuer->entity->z, $issuer->level->getName());
				if($plot === false){
					$output = 'You need to stand in a plot';
					break;
				}
				$comments = $this->comments[$plot['level']][$plot['id']];
				if(empty($comments)){
					$output = 'No comments in this plot';
					break;
				}
				$output = "==========[Comments]==========\n";
				$count = count($comments);
				if($count > 5){
					$totalPages = ceil($count/5);
					if(isset($args[1])){
						if(!is_numeric($args[1])){
							$output = 'Usage: /plot comments [page]';
							break;
						}elseif($args[1] > $totalPages or $args[1] < 1){
							$output = "That page doesn't exists";
							break;
						}else{
							$page = $args[1];
						}
					}else{
						$page = 1;
					}
					$output = '- Page '.$page.' of '.$totalPages."-\n";
					if($page === $totalPages){
						$comments = array_slice($comments, ($page * 5));
					}else{
						$comments = array_slice($comments, ($page * 5), 5);
					}
				}
				foreach($comments as $key => $comment){
					$output .= $comment['writer'].': '.$comment['message']."\n";
				}
				break;
				
			default:
				$output = "==========[Commands]==========\n";
				$output .= "/plot claim\n";
				$output .= "/plot auto\n";
				$output .= "/plot home\n";
				$output .= "/plot list\n";
				$output .= "/plot info\n";
				$output .= "/plot addhelper [name]\n";
				$output .= "/plot removehelper [name]\n";
				$output .= "/plot comment [msg]\n";
				$output .= "/plot comments <page>\n";
				$output .= "/plot clear\n";
				$output .= "/plot reset\n";
				break;
		}
		return $output;
	}
	
	public function resetPlot($plot){
		$thread = new ClearPlot($plot, $this->plotLevelInfo[$plot['level']], $this->api);
	}
	
	public function tpToPlot($plot, $player){
        $level = $plot['level'];
        $info = $this->plotLevelInfo[$level];
        $id = explode(';', $plot['id']);
        $middle = ceil($info['PlotSize']/ 2);
		$x = (($info['PlotSize'] + $info['RoadSize'] + 2) * $id[0]) + $middle;
        $z = (($info['PlotSize'] + $info['RoadSize'] + 2) * $id[1]) + $middle;
		$level = $this->api->level->get($plot['level']);
		$player->teleport(new Position($x, ($info['Height'] + 1), $z, $level));
	}
	
	public function getPlotsByOwner($username, $level = false){
        $username = strtolower($username);
        $plotsOwner = array();
		if($level === false){
            foreach($this->plots as $level => $plots){
                foreach($plots as $id => $val){
                    if($val['owner'] === $username){
                        $plotsOwner[$level][$id] = $val;
                    }
                }
            }
        }else{
            if(!isset($this->plots[$level])) return false;
            foreach($this->plots[$level] as $id => $val){
                if($val['owner'] === $username){
                    $plotsOwner[$level][$id] = $val;
                }
            }
        }
        if(empty($plotsOwner)) return false;
		return $plotsOwner;
	}
	
	public function getPlotByPos($x, $z, $level){
        $sizePlot = $this->plotLevelInfo[$level]['PlotSize'];
        $sizeRoad = $this->plotLevelInfo[$level]['RoadSize'];
        $totalSize = $sizePlot + $sizeRoad + 2;
        $rest = $x;
        $i = 0;
        while(1){
            if($rest < $totalSize){
                if($rest === 0 or $rest < 0){
                    return false;
                }
                if($rest < $sizePlot){
                    break;
                }
                return false;
            }
            $rest -= $totalSize;
            $i++;
        }
        $plot = (string)$i;
        $rest = $z;
        $i = 0;
        while(1){
            if($rest < $totalSize){
                if($rest === 0 or $rest < 0){
                    return false;
                }
                if($rest < $sizePlot){
                    break;
                }
                return false;
            }
            $rest -= $totalSize;
            $i++;
        }
        $plot .= ';'.$i;
        return $this->plots[$level][$plot];
	}
	
	public function block($data){
		$level = $data['player']->level->getName();
		if(isset($this->plots[$level])){
			if(!$this->api->ban->isOp($data['player']->username)){
				$username = $data['player']->iusername;
				$plot = $this->getPlotByPos($data['target']->x, $data['target']->z, $data['target']->level->getName());
				if(($plot === false) or ($plot['owner'] !== $username) or (!in_array($username, $plot['helpers']))){
					$data['player']->sendChat("You can't build in this plot");
					return false;
				}
			}
		}
	}
	
	public function createPlotWorld($name){
        self::$staticConfig = $this->config;
		$this->api->level->generateLevel($name, false, 'PlotPeGenerator');
        $this->api->level->loadLevel($name);
        $totalPlotsInRow = floor(256/($this->config['PlotSize'] + 2 + $this->config['RoadSize']));
        $comments = array();
        for($x = 0; $x <= $totalPlotsInRow; $x++){
            for($z = 0; $z <= $totalPlotsInRow; $z++){
                $id = (string)$x.';'.$z;
                $plots[$id] = array(
                    'id' => $id,
                    'level' => $name,
                    'owner' => false,
                    'helpers' => array(),
                    'expireDate' => false,
                );
                $comments[$id] = array();
            }
        }
		$this->plots[$name] = $plots;
		$this->comments[$name] = $comments;
        $this->plotLevelInfo[$name] = array(
            'PlotSize' => $this->config['PlotSize'],
            'RoadSize' => $this->config['RoadSize'],
            'PlotFillID' => $this->config['PlotFillingBlockId'],
            'PlotFloorID' => $this->config['PlotFloorBlockId'],
            'Height' => $this->config['Height'],
            'ExpireTime' => $this->config['ExpireAfterXDays'],
        );
        $data = array();
        $data['info'] = $this->plotLevelInfo[$name];
        $data['plots'] = $this->plots[$name];
        $data['comments'] = array();
        $this->api->plugin->writeYAML($this->path.'worlds/'.$name.'.yml', $data);
        $this->totalPlotWorlds++;
		console(FORMAT_GREEN.'PlotPe world generated succesfully!');
        return true;
	}
	
	public function __destruct(){}
}

class ClearPlot extends Thread{
	private $plot, $api, $plotInfo;
	public function __construct($plot, $plotInfo, $api){
		$this->plot = $plot;
		$this->api = $api;
		$this->plotInfo = $plotInfo;
	}
	
	public function run(){
		$level = $this->api->level->get($plot['level']);
		$sizePlot = $this->plotInfo['PlotSize'];
        $sizeRoad = $this->plotInfo['RoadSize'];
        $totalSize = $sizePlot + $sizeRoad + 2;
		$x = $this->plot['id'];
		$level->setBlockRaw(new Vector3($x,$y,$z), BlockAPI::get($this->yblocks[0][$y], 0), false, false);
		if($y < HEIGHT){
            return PLOT_FILL_ID;
        }
        if($y > (HEIGHT + 2)){
            return 0;
        }
		$height = $this->plotInfo['Height'];
		$blocks = array($this->plotInfo['PlotFillID'], $this->plotInfo['PlotFloorID']);
        $id = explode(';', $this->plot['id']);
		$x1 = $totalSize * $id[0];
        $z1 = $totalSize * $id[1];
		$x2 = $x1 + $sizePlot;
		$z2 = $z1 + $sizePlot;
		for(;$x1<$x2;$x1++){
			for(;$z1<$z2;$z1++){
				for($y=0;$y<$height;$y++){
					$level->setBlockRaw(new Vector3($x,$y,$z), BlockAPI::get($blocks[0], 0), false, false);
				}
				$level->setBlockRaw(new Vector3($x,$y,$z), BlockAPI::get($blocks[1], 0), false, false);
				for($y=$height+1;$y<128;$y++){
					$level->setBlockRaw(new Vector3($x,$y,$z), BlockAPI::get(0, 0), false, false);
				}
			}
		}
		return true;
	}
}


class PlotPeGenerator implements LevelGenerator{

    private $level, $shape;

    public function __construct(array $options = array()){

    }

    public function init(Level $level, Random $random){
        if(class_exists('PlotPe')){
            $config = PlotPe::$staticConfig;
            define('PLOT_SIZE', $config['PlotSize']);
            define('ROAD_SIZE', $config['RoadSize']);
            define('PLOT_FILL_ID', $config['PlotFillingBlockId']);
            define('PLOT_FLOOR_ID', $config['PlotFloorBlockId']);
            define('ROAD_ID', $config['RoadBlockId']);
            define('CORNER_ID', $config['CornerBlockId']);
            define('HEIGHT', $config['Height']);
        }else{
            define('PLOT_SIZE', 16);
            define('ROAD_SIZE', 3);
            define('PLOT_FILL_ID', 3);
            define('PLOT_FLOOR_ID', 2);
            define('ROAD_ID', 5);
            define('CORNER_ID', 44);
            define('HEIGHT', 27);
        }
		$this->level = $level;
        $this->generateShape();
    }

    public function generateChunk($chunkX, $chunkZ){
        for($Y = 0; $Y < 8; ++$Y){
            $chunk = "";
            $startY = $Y << 4;
            $endY = $startY + 16;
            for($z = 0; $z < 16; ++$z){
                for($x = 0; $x < 16; ++$x){
                    $blocks = "";
                    $metas = "";
                    for($y = $startY; $y < $endY; ++$y){
                        $blocks .= chr($this->pickBlock(($chunkX + $x), $y, ($chunkZ + $z)));
                        $metas .= "0";
                    }
                    $chunk .= $blocks.Utils::hexToStr($metas)."\x00\x00\x00\x00\x00\x00\x00\x00";
                }
            }
            $this->level->setMiniChunk($chunkX, $chunkZ, $Y, $chunk);
        }
    }

    public function populateChunk($chunkX, $chunkZ){

    }

    public function populateLevel(){

    }

    public function pickBlock($x, $y, $z){
        if($y < HEIGHT){
            return PLOT_FILL_ID;
        }
        if($y > (HEIGHT + 2)){
            return 0;
        }
        switch($this->shape[$z][$x]){
            case 0:
                if($y === HEIGHT){
                    return PLOT_FLOOR_ID;
                }
                return 0;
                break;
            case 1:
                if($y === HEIGHT){
                    return ROAD_ID;
                }
                return 0;
                break;
            case 2:
                if($y === HEIGHT){
                    return ROAD_ID;
                }
                return CORNER_ID;
                break;
        }
    }

    public function generateShape(){
        $width = 1;
        $length = 1;
        for($z = 1; $z < 256; $z++){
            if(($z - $length) <= PLOT_SIZE){
                $width = 1;
                for($x = 1; $x < 256; $x++){
                    if(($x - $width) <= PLOT_SIZE){
                        $shape[$z][$x] = 0;
                    }else{
                        $shape[$z][$x] = 2;
                        $startx = $x;
                        $x++;
                        for(; $x <= ($startx + ROAD_SIZE); $x++){
                            $shape[$z][$x] = 1;
                        }
                        $shape[$z][$x] = 2;
                        $width = $x + 1;
                    }
                }
            }else{
                $width = 1;
                for($x = 1; $x < 256; $x++){
                    if(($x - $width) <= PLOT_SIZE){
                        $shape[$z][$x] = 2;
                    }else{
                        $shape[$z][$x] = 2;
                        $startx = $x;
                        $x++;
                        for(;$x <= ($startx + ROAD_SIZE); $x++){
                            $shape[$z][$x] = 1;
                        }
                        $shape[$z][$x] = 2;
                        $width = $x + 1;
                    }
                }
                $size = $z + ROAD_SIZE;
                $z++;
                for(; $z <= $size; $z++){
                    for($x = 1; $x < 256; $x++){
                        $shape[$z][$x] = 1;
                    }
                }
                $width = 1;
                for($x = 1; $x < 256; $x++){
                    if(($x - $width) <= PLOT_SIZE){
                        $shape[$z][$x] = 2;
                    }else{
                        $shape[$z][$x] = 2;
                        $startx = $x;
                        $x++;
                        for(;$x <= ($startx + ROAD_SIZE); $x++){
                            $shape[$z][$x] = 1;
                        }
                        $shape[$z][$x] = 2;
                        $width = $x + 1;
                    }
                }
                $length = $z + 1;
            }
        }
        $z = 0;
        for($x = 0; $x < 256; $x++){
            $shape[$z][$x] = 2;
        }
        $x = 0;
        for($z = 0; $z < 256; $z++){
            $shape[$z][$x] = 2;
        }
        $this->shape = $shape;
    }

    public function getSpawn(){
        $totalplotsinrow = floor(256/(PLOT_SIZE + 2 + ROAD_SIZE));
        $totalplotblocksrow = $totalplotsinrow * (PLOT_SIZE + 2 + ROAD_SIZE);
        $middle = $totalplotblocksrow/2;
        return new Vector3($middle, HEIGHT + 1, $middle);
    }
}