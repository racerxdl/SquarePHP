<?php

use React\Promise\Timer;
use Ds\Queue;

include_once 'SquarePacket.php';
include_once 'SquareConstants.php';
include_once 'SquarePacketInclusion.php';
include_once 'SquareWorld/Player.php';
class ClientHandler
{
    // Gerenciador do servidor
    public ServerHandler $server;

    // Conexao com cliente
    private $conn;

    // Loop de Eventos do ReactPHP
    public $loop;

    public Ds\Queue $queue;

    // Player
    public $Player;

    // Estado Atual
    public $State;

    function __construct(React\EventLoop\StreamSelectLoop $loop, ServerHandler $server, React\Socket\ConnectionInterface $conn)
    {
        $this->server = $server;
        $this->loop = $loop;
        $this->conn = $conn;
        $this->queue = new Ds\Queue();
    }

    function GetMyPlayer()
    {
        return $this->Player;
    }

    function ProcessQueue() {
        if (!$this->conn->isWritable()) {
            Logger::getLogger("PHPServer")->error("Connection not open. Stopping queue process");
            return;
        }

        while ($this->queue->count()) {
            $packet = $this->queue->pop();
            $this->conn->write($packet);
        }

        Timer\resolve(1, $this->loop)->then(function () {
          $this->ProcessQueue();
        });
    }

    function onJoin($nick)
    {
        // J? est? logado.
        if ($this->Player != null) {
            return;
        }

        // For unauthenticated ("cracked"/offline-mode) and localhost connections (either of the two conditions is enough for an unencrypted connection) there is no encryption. In that case Login Start is directly followed by Login Success.
        $loginSuccess = new LoginSuccess($this, $nick);
        $loginSuccess->serialize();

        // Join game
        $JoinGame = new JoinGame($this);
        $JoinGame->ServerHandler = $this->server;
        $JoinGame->serialize();

        // Spawn Position.
        $Position = new Position($this);
        $Position->serialize();

        // Nome do servidor em jogo.
        $PluginMessage = new ServerPluginMessage($this);
        $PluginMessage->serialize();

        // Dificuldade no mundo
        $WorldDifficulty = new WorldDifficulty($this);
        $WorldDifficulty->ServerHandler = $this->server;
        $WorldDifficulty->serialize();

        // Player Abilities
        $PlayerAbilities = new PlayerAbilities($this);
        $PlayerAbilities->ServerHandler = $this->server;
        $PlayerAbilities->serialize();

        // Held Item Change
        $HeldItem = new HeldItemChange($this);
        $HeldItem->ServerHandler = $this->server;
        $HeldItem->serialize();

        // Chunk Data
        Timer\resolve(1, $this->loop)->then(function () {
            $ChunkData = new ChunkData($this);
            $ChunkData->ServerHandler = $this->server;
            $ChunkData->serialize();
        });

        // Create Player
        $this->Player = new Player($nick);

        // Start Ping
        $this->sendKeepAlive();

        // Variaveis
        $this->server->clientConnect();
        $this->server->AddPlayer($this->Player);

        Logger::getLogger("PHPServer")->info("Cliente " . $this->conn->getRemoteAddress() . " entrou no mundo!");
    }

    function do()
    {
        $this->conn->on('data', function ($data) {
            $this->onData($data);
        });

        $this->conn->on('end', function () {
            if ($this->Player != null) {
                $this->server->RemovePlayer($this->Player);
                $this->server->clientDisconnect();
            }
        });
        $this->conn->on('error', function () {
            if ($this->Player != null) {
                $this->server->RemovePlayer($this->Player);
                $this->server->clientDisconnect();
            }
        });

        Logger::getLogger("PHPServer")->info("Starting QUEUE PROCESS");
        $this->ProcessQueue();
    }

    public function SendPacket($data) {
        $this->queue->push($data);
//        $this->conn->write($data);
    }

    static function DecodePacket($handler, $data)
    {
        // Pacote
        $packet = new SquarePacket($handler);
        $packet->data = $data;

        // Pacote normais possui tamanho e packet ID.
        $packet->packetSize = $packet->DecodeVarInt();
        $packet->packetID = $packet->DecodeVarInt();

        // Return data.
        return $packet;
    }

    function onData($data)
    {
        // Retorna a classe Packet
        $SquarePacket = ClientHandler::DecodePacket($this, $data);

        // Packet handler
        $this->tryHandle($SquarePacket);
    }

    function SendWorldTime()
    {
        // TODO: Pegar em qual mundo o jogador est?, e usar no index.
        $WorldTime = $this->server->GetWorld(0)->GetWorldTime();
        $TotalWorldTime = $this->server->GetWorld(0)->GetTotalWorldTime();

        // Envia o World Time
        $WorldTimePacket = new SquarePacket($this);
        $WorldTimePacket->packetID = SquarePacketConstants::$SERVER_TIME_UPDATE;
        $WorldTimePacket->WriteLong($TotalWorldTime);
        $WorldTimePacket->WriteLong($WorldTime);
        $WorldTimePacket->SendPacket();
    }

    function sendKeepAlive()
    {
        // Manda o KeepAlive
        if ($this->conn->isWritable()) {
            $keepAlive = new KeepAlive($this);
            $keepAlive->serialize();
            Timer\resolve(1, $this->loop)->then(function () {
                $this->SendWorldTime();
                $this->sendKeepAlive();
            });
        }
    }

    function tryHandle($packet)
    {
        // Verifica se o pacote existe.
        if (!array_key_exists($packet->packetID, $GLOBALS["GamePackets"])) {
            Logger::getLogger("PHPServer")->warn("Cliente enviou um pacote invalido de ID 0x" . strtoupper(dechex($packet->packetID)) . " ({$packet->packetSize})");
            return;
        }

        // Lista de Pacotes
        $packetClassHandler = new $GLOBALS["GamePackets"][$packet->packetID]($this);

        // Leitura
        $packetClassHandler->data = $packet->data;
        $packetClassHandler->offset = $packet->offset;
        $packetClassHandler->packetSize = $packet->packetSize;
        $packetClassHandler->ServerHandler = $this->server;
        $packetClassHandler->deserialize();
    }
}
