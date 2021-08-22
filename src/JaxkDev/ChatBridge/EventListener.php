<?php
/*
 * ChatBridge, PocketMine-MP Plugin.
 *
 * Licensed under the Open Software License version 3.0 (OSL-3.0)
 * Copyright (C) 2020-2021 JaxkDev
 *
 * Twitter :: @JaxkDev
 * Discord :: JaxkDev#2698
 * Email   :: JaxkDev@gmail.com
 */

namespace JaxkDev\ChatBridge;

use JaxkDev\DiscordBot\Plugin\Events\DiscordClosed;
use JaxkDev\DiscordBot\Plugin\Events\DiscordReady;
use JaxkDev\DiscordBot\Plugin\Events\MessageSent;
use JaxkDev\DiscordBot\Plugin\Storage;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\plugin\PluginException;
use pocketmine\utils\Config;

class EventListener implements Listener{

    private bool $ready = false;
    private Main $plugin;
    private Config $config;

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
        $this->config = $this->plugin->getConfig();
    }

    public function onDiscordReady(DiscordReady $event): void{
        $this->ready = true;
    }

    public function onDiscordClosed(DiscordClosed $event): void{
        //This plugin can no longer function if discord closes, note once closed it will never start again until server restarts.
        $this->plugin->getLogger()->critical("DiscordBot has closed, disabling plugin.");
        $this->plugin->getServer()->getPluginManager()->disablePlugin($this->plugin);
    }

    /**
     * @priority MONITOR
     * @ignoreCancelled
     */
    public function onMinecraftMessage(PlayerChatEvent $event): void{
        if(!$this->ready){
            //TODO, Should we stack the messages up and send all 3/s when discords ready or....
            $this->plugin->getLogger()->debug("Ignoring chat event, discord is not ready.");
            return;
        }

        $config = $this->config->getNested("messages.minecraft");
        if(!$config['enabled']) return;

        $player = $event->getPlayer();
        $world = $player->getLevelNonNull()->getName();

    }

    /**
     * @priority MONITOR
     */
    public function onDiscordMessage(MessageSent $event): void{
        $config = $this->config->getNested("messages.discord");
        if(!$config['enabled']) return;

        $server_id = $event->getMessage()->getServerId();
        if($server_id === null){
            //DM Channel.
            $this->plugin->getLogger()->debug("Ignoring message '{$event->getMessage()->getId()}', Sent via DM to bot.");
            return;
        }
        $server = Storage::getServer($server_id);
        if($server === null){
            //shouldn't happen, but can.
            $this->plugin->getLogger()->warning("Failed to process discord message, server '{$event->getMessage()->getServerId()}' does not exist in local storage.");
            return;
        }
        $channel = Storage::getChannel($event->getMessage()->getChannelId());
        if($channel === null){
            //shouldn't happen, but can.
            $this->plugin->getLogger()->warning("Failed to process discord message, channel '{$event->getMessage()->getChannelId()}' does not exist in local storage.");
            return;
        }
        $member = Storage::getMember($event->getMessage()->getAuthorId()??"Will never be null");
        if($member === null){
            //shouldn't happen, but can.
            $this->plugin->getLogger()->warning("Failed to process discord message, author member '{$event->getMessage()->getAuthorId()}' does not exist in local storage.");
            return;
        }
        $user = Storage::getUser($member->getUserId());
        if($user === null){
            //shouldn't happen, but can.
            $this->plugin->getLogger()->warning("Failed to process discord message, author user '{$member->getUserId()}' does not exist in local storage.");
            return;
        }
        $content = trim($event->getMessage()->getContent());
        if(strlen($content) === 0){
            //Files or other type of messages.
            $this->plugin->getLogger()->debug("Ignoring message '{$event->getMessage()->getId()}', No text content.");
            return;
        }
        if(!in_array($channel->getId()??"Will never be null", $config['channels'])){
            $this->plugin->getLogger()->debug("Ignoring message from channel '{$channel->getId()}', ID is not in list.");
            return;
        }

        //Format message.
        $message = str_replace(['{NICKNAME}', '{nickname}'], $member->getNickname()??$user->getUsername(), $config['format']);
        $message = str_replace(['{USERNAME}', '{username}'], $user->getUsername(), $message);
        $message = str_replace(['{DISCRIMINATOR}', '{discriminator}'], $user->getDiscriminator(), $message);
        $message = str_replace(['{MESSAGE}', '{message'], $content, $message);
        $message = str_replace(['{SERVER}', '{server}'], $server->getName(), $message);
        $message = str_replace(['{CHANNEL}', '{channel}'], $channel->getName(), $message);
        if(!is_string($message)){
            throw new PluginException("A string is always expected, got '".gettype($message)."'");
        }

        //Broadcast.
        $worlds = $config['to_minecraft_worlds'];
        $players = [];

        if($worlds === "*" or (is_array($worlds) and sizeof($worlds) === 1 and $worlds[0] === "*")){
            $players = $this->plugin->getServer()->getOnlinePlayers();
        }else{
            foreach((is_array($worlds) ? $worlds : [$worlds]) as $world){
                $level = $this->plugin->getServer()->getLevelByName($world);
                if($level === null){
                    $this->plugin->getLogger()->warning("World '$world' listed in discord message config does not exist.");
                }else{
                    $players = array_merge($players, $level->getPlayers());
                }
            }
        }

        $this->plugin->getServer()->broadcastMessage($message, $players);
    }
}