-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: controle_big
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `comprovantes`
--

DROP TABLE IF EXISTS `comprovantes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comprovantes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entrega_id` int(11) NOT NULL,
  `arquivo` varchar(255) NOT NULL,
  `data_upload` datetime DEFAULT current_timestamp(),
  `usuario_id` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comprovantes`
--

LOCK TABLES `comprovantes` WRITE;
/*!40000 ALTER TABLE `comprovantes` DISABLE KEYS */;
/*!40000 ALTER TABLE `comprovantes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `entregas`
--

DROP TABLE IF EXISTS `entregas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `entregas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `loja_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `data_entrega` datetime DEFAULT current_timestamp(),
  `observacao` text DEFAULT NULL,
  `id_setor` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_entrega_setor` (`id_setor`),
  CONSTRAINT `fk_entrega_setor` FOREIGN KEY (`id_setor`) REFERENCES `setores` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `entregas`
--

LOCK TABLES `entregas` WRITE;
/*!40000 ALTER TABLE `entregas` DISABLE KEYS */;
INSERT INTO `entregas` VALUES (5,4,18,10,7,'2026-06-13 00:00:00','Solicitante: jack\nStatus: CONCLUIDA\nJustificativa: nao sei',4),(6,5,18,1,7,'2026-06-13 00:00:00','Solicitante: jack\nStatus: CONCLUIDA\nJustificativa: ok',2),(7,5,18,1,7,'2026-06-13 00:00:00','Solicitante: jack\nStatus: CONCLUIDA\nJustificativa: ok',2),(8,5,18,1,7,'2026-06-13 00:00:00','Solicitante: jack\nStatus: CONCLUIDA\nJustificativa: ok',2),(9,5,18,1,7,'2026-06-13 00:00:00','Solicitante: jack\nStatus: CONCLUIDA\nJustificativa: ok',2),(10,5,18,1,7,'2026-06-13 00:00:00','Solicitante: jack\nStatus: CONCLUIDA\nJustificativa: ok',2),(11,3,19,10,7,'2026-06-13 00:00:00','Solicitante: jjg\nStatus: CONCLUIDA\nJustificativa: bnasa as',1),(12,4,20,2,7,'2026-06-13 00:00:00','Solicitante: karla\nStatus: CONCLUIDA\nJustificativa: troca por motivo qualquer',3),(13,4,21,2,7,'2026-06-14 00:00:00','Solicitante: EuIGor\nStatus: CONCLUIDA\nJustificativa: Defeito fisico',2),(14,1,22,1,8,'2026-06-14 00:00:00','Solicitante: Lara\nStatus: CONCLUIDA\nJustificativa: O mouse estava com defeito',5),(15,8,23,2,8,'2026-06-14 00:00:00','Solicitante: jasmin\nStatus: CONCLUIDA\nJustificativa: ruim',3),(16,5,24,4,8,'2026-06-14 00:00:00','Solicitante: Nubia\nStatus: CONCLUIDA\nJustificativa: Deu defeito.',4),(17,4,22,6,8,'2026-06-14 00:00:00','Solicitante: Lara\nStatus: CONCLUIDA\nJustificativa: ok',3),(18,8,25,2,7,'2026-06-16 00:00:00','Solicitante: Thaisa\nStatus: CONCLUIDA\nJustificativa: lazer ruim',4),(19,8,22,8,8,'2026-06-19 00:00:00','Solicitante: Lara\nStatus: CONCLUIDA\nJustificativa: Mau contato na fonte',2),(20,11,22,5,8,'2026-06-21 00:00:00','Solicitante: Lara\nStatus: CONCLUIDA\nJustificativa: nada',5),(21,12,20,10,8,'2026-06-22 12:18:49','Solicitante: karla\nStatus: CONCLUIDA\nJustificativa: Solicitação de demanda.',4),(22,5,26,10,8,'2026-06-22 22:19:46','Solicitante: Maria\nStatus: CONCLUIDA\nJustificativa: Solicitação de compras',4);
/*!40000 ALTER TABLE `entregas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `estoque_equipamentos`
--

DROP TABLE IF EXISTS `estoque_equipamentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `estoque_equipamentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `produto_id` int(11) NOT NULL,
  `loja_id` int(11) DEFAULT NULL,
  `quantidade` int(11) NOT NULL DEFAULT 0,
  `data_atualizacao` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_estoque_produto_loja` (`produto_id`,`loja_id`),
  KEY `fk_estoque_loja` (`loja_id`),
  CONSTRAINT `fk_estoque_loja` FOREIGN KEY (`loja_id`) REFERENCES `lojas` (`id`),
  CONSTRAINT `fk_estoque_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `estoque_equipamentos`
--

LOCK TABLES `estoque_equipamentos` WRITE;
/*!40000 ALTER TABLE `estoque_equipamentos` DISABLE KEYS */;
INSERT INTO `estoque_equipamentos` VALUES (3,3,3,183,'2026-06-13 12:02:46'),(4,4,1,17,'2026-06-14 21:09:58'),(5,5,2,56,'2026-06-22 22:19:46'),(7,15,NULL,5,'2026-06-20 11:40:39'),(8,16,NULL,4,'2026-06-21 09:23:05'),(9,17,NULL,4,'2026-06-23 07:18:11');
/*!40000 ALTER TABLE `estoque_equipamentos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fotos_equipamentos`
--

DROP TABLE IF EXISTS `fotos_equipamentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fotos_equipamentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `arquivo` varchar(255) NOT NULL,
  `data_upload` datetime DEFAULT current_timestamp(),
  `usuario_id` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fotos_equipamentos`
--

LOCK TABLES `fotos_equipamentos` WRITE;
/*!40000 ALTER TABLE `fotos_equipamentos` DISABLE KEYS */;
/*!40000 ALTER TABLE `fotos_equipamentos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `funcionarios`
--

DROP TABLE IF EXISTS `funcionarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `funcionarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(150) NOT NULL,
  `cpf` varchar(14) DEFAULT NULL,
  `cargo` varchar(80) DEFAULT NULL,
  `loja_id` int(11) DEFAULT NULL,
  `setor_id` int(11) DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `data_criacao` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `funcionarios`
--

LOCK TABLES `funcionarios` WRITE;
/*!40000 ALTER TABLE `funcionarios` DISABLE KEYS */;
INSERT INTO `funcionarios` VALUES (1,'João Silva','111.111.111-11','Gerente',1,NULL,1,'2026-06-10 22:12:20'),(2,'Maria Santos','222.222.222-22','Vendedor',1,NULL,1,'2026-06-10 22:12:20'),(3,'Pedro Costa','333.333.333-33','Vendedor',2,NULL,1,'2026-06-10 22:12:20'),(4,'Igor Oliveira','111.111.111-11','Administrador',1,1,1,'2026-06-13 05:43:43'),(5,'Mariana Costa','222.222.222-22','Analista',2,3,1,'2026-06-13 05:43:43'),(6,'Carlos Lima','333.333.333-33','Atendente',3,4,1,'2026-06-13 05:43:43'),(7,'Paulo Mendes','444.444.444-44','Assistente',1,2,1,'2026-06-13 05:43:43'),(8,'Juliana Silva','555.555.555-55','Analista RH',2,5,1,'2026-06-13 05:43:43'),(9,'Igor Oliveira','111.111.111-11','Administrador',1,1,1,'2026-06-13 05:44:46'),(10,'Mariana Costa','222.222.222-22','Analista',2,3,1,'2026-06-13 05:44:46'),(11,'Carlos Lima','333.333.333-33','Atendente',3,4,1,'2026-06-13 05:44:46'),(12,'Paulo Mendes','444.444.444-44','Assistente',1,2,1,'2026-06-13 05:44:46'),(13,'Juliana Silva','555.555.555-55','Analista RH',2,5,1,'2026-06-13 05:44:46'),(18,'jack',NULL,NULL,10,4,1,'2026-06-13 09:57:34'),(19,'jjg',NULL,NULL,10,1,1,'2026-06-13 12:02:46'),(20,'karla',NULL,NULL,2,3,1,'2026-06-13 18:09:48'),(21,'EuIGor',NULL,NULL,2,2,1,'2026-06-14 09:39:59'),(22,'Lara',NULL,NULL,1,5,1,'2026-06-14 12:25:06'),(23,'jasmin',NULL,NULL,2,3,1,'2026-06-14 12:42:52'),(24,'Nubia',NULL,NULL,4,4,1,'2026-06-14 18:30:46'),(25,'Thaisa',NULL,NULL,2,4,1,'2026-06-15 23:25:42'),(26,'Maria',NULL,NULL,10,4,1,'2026-06-22 22:19:46');
/*!40000 ALTER TABLE `funcionarios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `itens`
--

DROP TABLE IF EXISTS `itens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `itens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `produto_id` int(11) NOT NULL,
  `serial` varchar(80) NOT NULL,
  `patrimonio` varchar(80) DEFAULT NULL,
  `status` varchar(30) DEFAULT 'Estoque',
  `observacao` text DEFAULT NULL,
  `data_ultima_manutencao` date DEFAULT NULL,
  `observacao_manutencao` text DEFAULT NULL,
  `data_criacao` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `serial` (`serial`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `itens`
--

LOCK TABLES `itens` WRITE;
/*!40000 ALTER TABLE `itens` DISABLE KEYS */;
INSERT INTO `itens` VALUES (1,1,'MON-0001','PAT001','Estoque',NULL,'2026-01-15',NULL,'2026-06-13 05:43:43'),(2,2,'TEC-0001','PAT002','Estoque',NULL,'2026-02-10',NULL,'2026-06-13 05:43:43'),(3,3,'MOU-0001','PAT003','Estoque',NULL,NULL,NULL,'2026-06-13 05:43:43'),(4,4,'HDM-0001','PAT004','Estoque',NULL,NULL,NULL,'2026-06-13 05:43:43'),(5,5,'NTB-0001','PAT005','Estoque',NULL,'2025-12-01',NULL,'2026-06-13 05:43:43'),(8,15,'AUTO-15-20260614174252-1',NULL,'Estoque','Item operacional criado automaticamente a partir do estoque para permitir registro de movimentação. Produto: Mouse',NULL,NULL,'2026-06-14 12:42:52'),(9,5,'AUTO-5-TROCA-NOVO-20260614233046-1',NULL,'Estoque','Item operacional criado automaticamente a partir do estoque para permitir registro de movimentação. Produto: Monitor 24\"',NULL,NULL,'2026-06-14 18:30:46'),(10,15,'AUTO-15-TROCA-NOVO-20260616042542-1',NULL,'Estoque','Item operacional criado automaticamente a partir do estoque para permitir registro de movimentação. Produto: Mouse',NULL,NULL,'2026-06-15 23:25:42'),(11,16,'AUTO-16-ENTREGA-20260621142305-1',NULL,'Estoque','Item operacional criado automaticamente a partir do estoque para permitir registro de movimentação. Produto: Teclado',NULL,NULL,'2026-06-21 09:23:05'),(12,17,'AUTO-17-ENTREGA-20260622121849-1',NULL,'Estoque','Item operacional criado automaticamente a partir do estoque para permitir registro de movimentação. Produto: Fonte',NULL,NULL,'2026-06-22 07:18:49');
/*!40000 ALTER TABLE `itens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lojas`
--

DROP TABLE IF EXISTS `lojas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lojas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(150) NOT NULL,
  `endereco` varchar(255) DEFAULT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `data_criacao` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lojas`
--

LOCK TABLES `lojas` WRITE;
/*!40000 ALTER TABLE `lojas` DISABLE KEYS */;
INSERT INTO `lojas` VALUES (1,'Loja 01',NULL,NULL,1,'2026-06-10 22:12:20'),(2,'Loja 02',NULL,NULL,1,'2026-06-10 22:12:20'),(3,'Loja 3',NULL,NULL,0,'2026-06-10 22:12:20'),(4,'Loja 04',NULL,NULL,1,'2026-06-10 22:12:20'),(5,'Loja 05',NULL,NULL,1,'2026-06-10 22:12:20'),(6,'Loja 06',NULL,NULL,1,'2026-06-10 22:12:20'),(7,'Loja 7',NULL,NULL,0,'2026-06-10 22:12:20'),(8,'Loja 08',NULL,NULL,1,'2026-06-10 22:12:20'),(9,'Loja 09',NULL,NULL,1,'2026-06-10 22:12:20'),(10,'Depósito 73',NULL,NULL,1,'2026-06-10 22:12:20'),(11,'Depósito 77',NULL,NULL,1,'2026-06-10 22:12:20'),(12,'Loja 01',NULL,NULL,0,'2026-06-13 05:43:43'),(13,'Loja 02',NULL,NULL,0,'2026-06-13 05:43:43'),(14,'Loja 03',NULL,NULL,0,'2026-06-13 05:43:43'),(15,'Loja 04',NULL,NULL,0,'2026-06-13 05:43:43'),(16,'Loja 05',NULL,NULL,0,'2026-06-13 05:43:43'),(17,'Loja 06',NULL,NULL,0,'2026-06-13 05:43:43'),(18,'Loja 07',NULL,NULL,0,'2026-06-13 05:43:43'),(19,'Loja 08',NULL,NULL,0,'2026-06-13 05:43:43'),(20,'Loja 09',NULL,NULL,0,'2026-06-13 05:43:43'),(21,'Deposito 73',NULL,NULL,0,'2026-06-13 05:43:43'),(22,'Deposito 77',NULL,NULL,0,'2026-06-13 05:43:43'),(23,'Loja 01',NULL,NULL,0,'2026-06-13 05:44:46'),(24,'Loja 02',NULL,NULL,0,'2026-06-13 05:44:46'),(25,'Loja 03',NULL,NULL,0,'2026-06-13 05:44:46'),(26,'Loja 04',NULL,NULL,0,'2026-06-13 05:44:46'),(27,'Loja 05',NULL,NULL,0,'2026-06-13 05:44:46'),(28,'Loja 06',NULL,NULL,0,'2026-06-13 05:44:46'),(29,'Loja 07',NULL,NULL,0,'2026-06-13 05:44:46'),(30,'Loja 08',NULL,NULL,0,'2026-06-13 05:44:46'),(31,'Loja 09',NULL,NULL,0,'2026-06-13 05:44:46'),(32,'Deposito 73',NULL,NULL,0,'2026-06-13 05:44:46'),(33,'Deposito 77',NULL,NULL,0,'2026-06-13 05:44:46');
/*!40000 ALTER TABLE `lojas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lojas_backup_padronizacao_20260618`
--

DROP TABLE IF EXISTS `lojas_backup_padronizacao_20260618`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lojas_backup_padronizacao_20260618` (
  `id` int(11) NOT NULL DEFAULT 0,
  `nome` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `endereco` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `telefone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `data_criacao` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lojas_backup_padronizacao_20260618`
--

LOCK TABLES `lojas_backup_padronizacao_20260618` WRITE;
/*!40000 ALTER TABLE `lojas_backup_padronizacao_20260618` DISABLE KEYS */;
INSERT INTO `lojas_backup_padronizacao_20260618` VALUES (1,'Loja 1',NULL,NULL,1,'2026-06-10 22:12:20'),(2,'Loja 2',NULL,NULL,1,'2026-06-10 22:12:20'),(3,'Loja 3',NULL,NULL,1,'2026-06-10 22:12:20'),(4,'Loja 4',NULL,NULL,1,'2026-06-10 22:12:20'),(5,'Loja 5',NULL,NULL,1,'2026-06-10 22:12:20'),(6,'Loja 6',NULL,NULL,1,'2026-06-10 22:12:20'),(7,'Loja 7',NULL,NULL,1,'2026-06-10 22:12:20'),(8,'Loja 8',NULL,NULL,1,'2026-06-10 22:12:20'),(9,'Loja 9',NULL,NULL,1,'2026-06-10 22:12:20'),(10,'Depósito 73',NULL,NULL,1,'2026-06-10 22:12:20'),(11,'Depósito 77',NULL,NULL,1,'2026-06-10 22:12:20'),(12,'Loja 01',NULL,NULL,1,'2026-06-13 05:43:43'),(13,'Loja 02',NULL,NULL,1,'2026-06-13 05:43:43'),(14,'Loja 03',NULL,NULL,1,'2026-06-13 05:43:43'),(15,'Loja 04',NULL,NULL,1,'2026-06-13 05:43:43'),(16,'Loja 05',NULL,NULL,1,'2026-06-13 05:43:43'),(17,'Loja 06',NULL,NULL,1,'2026-06-13 05:43:43'),(18,'Loja 07',NULL,NULL,1,'2026-06-13 05:43:43'),(19,'Loja 08',NULL,NULL,1,'2026-06-13 05:43:43'),(20,'Loja 09',NULL,NULL,1,'2026-06-13 05:43:43'),(21,'Deposito 73',NULL,NULL,1,'2026-06-13 05:43:43'),(22,'Deposito 77',NULL,NULL,1,'2026-06-13 05:43:43'),(23,'Loja 01',NULL,NULL,1,'2026-06-13 05:44:46'),(24,'Loja 02',NULL,NULL,1,'2026-06-13 05:44:46'),(25,'Loja 03',NULL,NULL,1,'2026-06-13 05:44:46'),(26,'Loja 04',NULL,NULL,1,'2026-06-13 05:44:46'),(27,'Loja 05',NULL,NULL,1,'2026-06-13 05:44:46'),(28,'Loja 06',NULL,NULL,1,'2026-06-13 05:44:46'),(29,'Loja 07',NULL,NULL,1,'2026-06-13 05:44:46'),(30,'Loja 08',NULL,NULL,1,'2026-06-13 05:44:46'),(31,'Loja 09',NULL,NULL,1,'2026-06-13 05:44:46'),(32,'Deposito 73',NULL,NULL,1,'2026-06-13 05:44:46'),(33,'Deposito 77',NULL,NULL,1,'2026-06-13 05:44:46');
/*!40000 ALTER TABLE `lojas_backup_padronizacao_20260618` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `manutencoes`
--

DROP TABLE IF EXISTS `manutencoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `manutencoes` (
  `id_manutencao` int(11) NOT NULL AUTO_INCREMENT,
  `id_item` int(11) NOT NULL,
  `id_loja` int(11) NOT NULL,
  `descricao` text NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'EM_MANUTENCAO',
  `data_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `id_usuario` int(11) NOT NULL,
  `data_conclusao` datetime DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id_manutencao`),
  KEY `idx_manutencoes_data` (`data_registro`),
  KEY `idx_manutencoes_item` (`id_item`),
  KEY `idx_manutencoes_loja` (`id_loja`),
  KEY `idx_manutencoes_usuario` (`id_usuario`),
  KEY `idx_manutencoes_status` (`status`),
  KEY `idx_manutencoes_data_registro` (`data_registro`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `manutencoes`
--

LOCK TABLES `manutencoes` WRITE;
/*!40000 ALTER TABLE `manutencoes` DISABLE KEYS */;
INSERT INTO `manutencoes` VALUES (1,4,4,'Problema na placa','CONCLUIDO','2026-06-19 00:46:54',1,'2026-06-21 19:04:32',1),(2,15,1,'ruim','CONCLUIDO','2026-06-19 00:59:19',1,'2026-06-21 19:04:30',1),(3,5,2,'lol','CONCLUIDO','2026-06-19 01:09:42',1,'2026-06-21 19:04:24',1),(4,4,4,'ok','CONCLUIDO','2026-06-22 06:37:54',1,'2026-06-22 06:38:15',1),(5,4,4,'ok','CONCLUIDO','2026-06-22 06:37:54',1,'2026-06-22 06:38:13',1),(6,3,1,'ruim','CONCLUIDO','2026-06-22 06:43:52',1,'2026-06-22 06:44:11',1);
/*!40000 ALTER TABLE `manutencoes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `movimentacoes`
--

DROP TABLE IF EXISTS `movimentacoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `movimentacoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` varchar(50) NOT NULL,
  `produto_id` int(11) DEFAULT NULL,
  `item_id` int(11) DEFAULT NULL,
  `loja_id` int(11) DEFAULT NULL,
  `setor_id` int(11) DEFAULT NULL,
  `funcionario_id` int(11) DEFAULT NULL,
  `solicitante_nome` varchar(150) DEFAULT NULL,
  `quantidade` int(11) NOT NULL DEFAULT 1,
  `status` varchar(30) NOT NULL DEFAULT 'Pendente',
  `justificativa` text DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `descricao` text DEFAULT NULL,
  `data_movimentacao` datetime DEFAULT current_timestamp(),
  `data_conclusao` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `movimentacoes`
--

LOCK TABLES `movimentacoes` WRITE;
/*!40000 ALTER TABLE `movimentacoes` DISABLE KEYS */;
INSERT INTO `movimentacoes` VALUES (10,'Entrega',4,4,10,4,18,'jack',5,'CONCLUIDA','nao sei',7,'Entrega de 5 unidade(s) de Computador','2026-06-13 00:00:00','2026-06-13 09:57:34'),(11,'Entrega',5,5,1,2,18,'jack',8,'CONCLUIDA','ok',7,'Entrega de 8 unidade(s) de Monitor 24\"','2026-06-13 00:00:00','2026-06-13 09:57:56'),(12,'Entrega',5,5,1,2,18,'jack',8,'CONCLUIDA','ok',7,'Entrega de 8 unidade(s) de Monitor 24\"','2026-06-13 00:00:00','2026-06-13 10:32:30'),(13,'Entrega',5,5,1,2,18,'jack',8,'CONCLUIDA','ok',7,'Entrega de 8 unidade(s) de Monitor 24\"','2026-06-13 00:00:00','2026-06-13 10:49:57'),(14,'Entrega',5,5,1,2,18,'jack',8,'CONCLUIDA','ok',7,'Entrega de 8 unidade(s) de Monitor 24\"','2026-06-13 00:00:00','2026-06-13 10:55:22'),(15,'Entrega',5,5,1,2,18,'jack',8,'CONCLUIDA','ok',7,'Entrega de 8 unidade(s) de Monitor 24\"','2026-06-13 00:00:00','2026-06-13 10:55:29'),(16,'Troca',3,3,10,1,19,'jjg',3,'CONCLUIDA','bnasa as',7,'Troca de 3 unidade(s) de Impressora HP Multifuncional','2026-06-13 00:00:00','2026-06-13 12:02:46'),(17,'Entrega',4,4,2,3,20,'karla',1,'CONCLUIDA','troca por motivo qualquer',7,'Entrega de 1 unidade(s) de Computador','2026-06-13 00:00:00','2026-06-13 18:09:48'),(18,'Troca',4,4,2,2,21,'EuIGor',1,'CONCLUIDA','Defeito fisico',7,'Troca de 1 unidade(s) de Computador','2026-06-14 00:00:00','2026-06-14 09:39:59'),(19,'Troca',1,1,1,5,22,'Lara',1,'CONCLUIDA','O mouse estava com defeito',8,'Troca de 1 unidade(s) de Notebook Dell Latitude','2026-06-14 00:00:00','2026-06-14 12:25:06'),(20,'Troca',15,8,2,3,23,'jasmin',2,'CONCLUIDA','ruim',8,'Troca de 2 unidade(s) de Mouse','2026-06-14 00:00:00','2026-06-14 12:42:52'),(21,'Troca',5,5,4,4,24,'Nubia',1,'CONCLUIDA','Deu defeito.',8,'Troca de 1 unidade(s) de Monitor 24\"','2026-06-14 00:00:00','2026-06-14 18:30:46'),(22,'Entrega',4,4,6,3,22,'Lara',1,'CONCLUIDA','ok',8,'Entrega de 1 unidade(s) de Computador','2026-06-14 00:00:00','2026-06-14 21:09:58'),(23,'Troca',15,8,2,4,25,'Thaisa',3,'CONCLUIDA','lazer ruim',7,'Troca de 3 unidade(s) de Mouse','2026-06-16 04:25:42','2026-06-15 23:25:42'),(24,'Entrega',15,8,8,2,22,'Lara',5,'CONCLUIDA','Mau contato na fonte',8,'Entrega de 5 unidade(s) de Mouse','2026-06-19 04:07:34','2026-06-18 23:07:34'),(25,'Entrega',16,11,5,5,22,'Lara',1,'CONCLUIDA','nada',8,'Entrega de 1 unidade(s) de Teclado','2026-06-21 14:23:05','2026-06-21 09:23:05'),(26,'Entrega',17,12,10,4,20,'karla',2,'CONCLUIDA','Solicitação de demanda.',8,'Entrega de 2 unidade(s) de Fonte','2026-06-22 07:18:49','2026-06-22 07:18:49'),(27,'Entrega',5,5,10,4,26,'Maria',1,'CONCLUIDA','Solicitação de compras',8,'Entrega de 1 unidade(s) de Monitor 24\"','2026-06-22 22:19:46','2026-06-22 22:19:46');
/*!40000 ALTER TABLE `movimentacoes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `produtos`
--

DROP TABLE IF EXISTS `produtos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `produtos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(150) NOT NULL,
  `descricao` text DEFAULT NULL,
  `categoria` varchar(80) DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `data_criacao` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `produtos`
--

LOCK TABLES `produtos` WRITE;
/*!40000 ALTER TABLE `produtos` DISABLE KEYS */;
INSERT INTO `produtos` VALUES (1,'Notebook Dell Latitude','Notebook corporativo 14\"','Computador',1,'2026-06-10 22:12:20'),(2,'Monitor LG 24\"','Monitor Full HD','Periférico',1,'2026-06-10 22:12:20'),(3,'Impressora HP Multifuncional','Multifuncional laser','Impressora',1,'2026-06-10 22:12:20'),(4,'Computador','','hadware',1,'2026-06-10 23:34:55'),(5,'Monitor 24\"','Monitor Full HD de 24 polegadas','Periferico',1,'2026-06-13 05:43:43'),(6,'Teclado Logitech','Teclado USB Logitech','Periferico',1,'2026-06-13 05:43:43'),(7,'Mouse Dell','Mouse optico Dell','Periferico',1,'2026-06-13 05:43:43'),(8,'Cabo HDMI','Cabo HDMI padrao','Cabo',1,'2026-06-13 05:43:43'),(9,'Notebook Dell','Notebook corporativo Dell','Computador',1,'2026-06-13 05:43:43'),(10,'Monitor 24\"','Monitor Full HD de 24 polegadas','Periferico',1,'2026-06-13 05:44:46'),(11,'Teclado Logitech','Teclado USB Logitech','Periferico',1,'2026-06-13 05:44:46'),(12,'Mouse Dell','Mouse optico Dell','Periferico',1,'2026-06-13 05:44:46'),(13,'Cabo HDMI','Cabo HDMI padrao','Cabo',1,'2026-06-13 05:44:46'),(14,'Notebook Dell','Notebook corporativo Dell','Computador',1,'2026-06-13 05:44:46'),(15,'Mouse',NULL,'Estoque',1,'2026-06-13 09:35:47'),(16,'Teclado',NULL,'Estoque',1,'2026-06-18 23:09:37'),(17,'Fonte',NULL,'Estoque',1,'2026-06-22 07:16:54');
/*!40000 ALTER TABLE `produtos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `setores`
--

DROP TABLE IF EXISTS `setores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `setores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `icone` varchar(40) DEFAULT NULL,
  `cor` varchar(20) DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `data_criacao` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `setores`
--

LOCK TABLES `setores` WRITE;
/*!40000 ALTER TABLE `setores` DISABLE KEYS */;
INSERT INTO `setores` VALUES (1,'Informatica','monitor','#1E90FF',1,'2026-06-13 05:44:46'),(2,'Administrativo','briefcase','#9B5DE5',1,'2026-06-13 05:44:46'),(3,'Financeiro','circle-dollar-sign','#22C55E',1,'2026-06-13 05:44:46'),(4,'Atendimento','headphones','#F59E0B',1,'2026-06-13 05:44:46'),(5,'Recursos Humanos','users','#FACC15',1,'2026-06-13 05:44:46'),(6,'Outros','archive','#94A3B8',1,'2026-06-13 05:44:46');
/*!40000 ALTER TABLE `setores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `trocas`
--

DROP TABLE IF EXISTS `trocas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `trocas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_antigo_id` int(11) NOT NULL,
  `item_novo_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `loja_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `data_troca` datetime DEFAULT current_timestamp(),
  `motivo` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trocas`
--

LOCK TABLES `trocas` WRITE;
/*!40000 ALTER TABLE `trocas` DISABLE KEYS */;
INSERT INTO `trocas` VALUES (2,3,3,19,10,7,'2026-06-13 00:00:00','bnasa as'),(3,4,4,21,2,7,'2026-06-14 00:00:00','Defeito fisico'),(4,1,1,22,1,8,'2026-06-14 00:00:00','O mouse estava com defeito'),(5,8,8,23,2,8,'2026-06-14 00:00:00','ruim'),(6,5,9,24,4,8,'2026-06-14 00:00:00','Deu defeito.'),(7,8,10,25,2,7,'2026-06-16 00:00:00','lazer ruim');
/*!40000 ALTER TABLE `trocas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(150) NOT NULL,
  `usuario` varchar(50) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `nivel` varchar(20) DEFAULT 'operador',
  `ativo` tinyint(1) DEFAULT 1,
  `data_criacao` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `usuario` (`usuario`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usuarios`
--

LOCK TABLES `usuarios` WRITE;
/*!40000 ALTER TABLE `usuarios` DISABLE KEYS */;
INSERT INTO `usuarios` VALUES (1,'Administrador','admin','$2y$10$1EBAOVji85fRzhXoRwMIne/KCzMPd1Ug.9xJuKQUkYmSN/kv4r2pi','admin',1,'2026-06-10 22:12:20'),(2,'Igor DEV','igor.dev','$2y$10$wKMib3xOD3YOl5l.3qXrOu7KH0jC/CFd4BwDQgG.SCIZd42/KVva2','admin',1,'2026-06-10 22:12:20'),(3,'carla','carla','$2y$10$/M4ugxDcB1uxZpavQM6QLe70yQSNVN.bANDPEfvJvMmqLgi4GSksq','operador',1,'2026-06-10 23:30:07'),(4,'Igor Oliveira','igor','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','TECNICO',0,'2026-06-13 05:43:43'),(7,'Kaio','Kaio12','$2y$10$lLfZfF4ijKCzM8d.Gx5zHel/hJSAcMmAAQBVU.S86WkWTCDPEOB1S','TECNICO',1,'2026-06-13 09:34:28'),(8,'Genilda','Genilda22','$2y$10$3XZJ5exSFzNH2sciwL0o/Ox1i7GAuGHhQDhfSq7sqO2Nlw/dqGtQW','operador',1,'2026-06-14 12:23:51'),(9,'Carlosmin','Carlos321','$2y$10$sEYdKjR7rmfnGCHZ0VLEiu7GvksIRLqs19tIKECCqvHSLen4neen2','admin',1,'2026-06-22 06:48:21'),(10,'Carlos','Carlos','$2y$10$gqQNaNNWRgGTNOPhn.eUKuTuG9b7Wh4qTYwS4hflNSf0i28uZO1Iy','admin',1,'2026-06-22 06:49:13');
/*!40000 ALTER TABLE `usuarios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary table structure for view `vw_consulta_movimentacoes`
--

DROP TABLE IF EXISTS `vw_consulta_movimentacoes`;
/*!50001 DROP VIEW IF EXISTS `vw_consulta_movimentacoes`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_consulta_movimentacoes` AS SELECT
 1 AS `id`,
  1 AS `data_entrega`,
  1 AS `solicitante`,
  1 AS `loja`,
  1 AS `setor_destino`,
  1 AS `equipamento`,
  1 AS `quantidade`,
  1 AS `tipo`,
  1 AS `status`,
  1 AS `usuario`,
  1 AS `justificativa` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vw_dashboard_cards`
--

DROP TABLE IF EXISTS `vw_dashboard_cards`;
/*!50001 DROP VIEW IF EXISTS `vw_dashboard_cards`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_dashboard_cards` AS SELECT
 1 AS `usuarios_ativos`,
  1 AS `movimentacoes_mes`,
  1 AS `trocas_mes`,
  1 AS `em_avaliacao` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vw_dashboard_movimentos_mensais`
--

DROP TABLE IF EXISTS `vw_dashboard_movimentos_mensais`;
/*!50001 DROP VIEW IF EXISTS `vw_dashboard_movimentos_mensais`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_dashboard_movimentos_mensais` AS SELECT
 1 AS `ano_mes`,
  1 AS `entregas`,
  1 AS `trocas`,
  1 AS `total` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vw_equipamentos_por_setor`
--

DROP TABLE IF EXISTS `vw_equipamentos_por_setor`;
/*!50001 DROP VIEW IF EXISTS `vw_equipamentos_por_setor`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_equipamentos_por_setor` AS SELECT
 1 AS `setor`,
  1 AS `total_movimentacoes`,
  1 AS `total_equipamentos` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vw_movimentacoes_por_tipo`
--

DROP TABLE IF EXISTS `vw_movimentacoes_por_tipo`;
/*!50001 DROP VIEW IF EXISTS `vw_movimentacoes_por_tipo`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_movimentacoes_por_tipo` AS SELECT
 1 AS `tipo`,
  1 AS `total`,
  1 AS `percentual` */;
SET character_set_client = @saved_cs_client;

--
-- Final view structure for view `vw_consulta_movimentacoes`
--

/*!50001 DROP VIEW IF EXISTS `vw_consulta_movimentacoes`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = cp850 */;
/*!50001 SET character_set_results     = cp850 */;
/*!50001 SET collation_connection      = cp850_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_consulta_movimentacoes` AS select `m`.`id` AS `id`,`m`.`data_movimentacao` AS `data_entrega`,`m`.`solicitante_nome` AS `solicitante`,`l`.`nome` AS `loja`,`s`.`nome` AS `setor_destino`,`p`.`nome` AS `equipamento`,`m`.`quantidade` AS `quantidade`,`m`.`tipo` AS `tipo`,`m`.`status` AS `status`,`u`.`nome` AS `usuario`,`m`.`justificativa` AS `justificativa` from ((((`movimentacoes` `m` join `lojas` `l` on(`l`.`id` = `m`.`loja_id`)) join `setores` `s` on(`s`.`id` = `m`.`setor_id`)) left join `produtos` `p` on(`p`.`id` = `m`.`produto_id`)) left join `usuarios` `u` on(`u`.`id` = `m`.`usuario_id`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_dashboard_cards`
--

/*!50001 DROP VIEW IF EXISTS `vw_dashboard_cards`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = cp850 */;
/*!50001 SET character_set_results     = cp850 */;
/*!50001 SET collation_connection      = cp850_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_dashboard_cards` AS select (select count(0) from `usuarios` where `usuarios`.`ativo` = 1) AS `usuarios_ativos`,(select count(0) from `movimentacoes` where month(`movimentacoes`.`data_movimentacao`) = month(curdate()) and year(`movimentacoes`.`data_movimentacao`) = year(curdate())) AS `movimentacoes_mes`,(select count(0) from `movimentacoes` where `movimentacoes`.`tipo` = 'Troca' and month(`movimentacoes`.`data_movimentacao`) = month(curdate()) and year(`movimentacoes`.`data_movimentacao`) = year(curdate())) AS `trocas_mes`,(select count(0) from `movimentacoes` where `movimentacoes`.`status` in ('Pendente','Em avaliacao')) AS `em_avaliacao` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_dashboard_movimentos_mensais`
--

/*!50001 DROP VIEW IF EXISTS `vw_dashboard_movimentos_mensais`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = cp850 */;
/*!50001 SET character_set_results     = cp850 */;
/*!50001 SET collation_connection      = cp850_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_dashboard_movimentos_mensais` AS select date_format(`movimentacoes`.`data_movimentacao`,'%Y-%m') AS `ano_mes`,sum(`movimentacoes`.`tipo` = 'Entrega') AS `entregas`,sum(`movimentacoes`.`tipo` = 'Troca') AS `trocas`,count(0) AS `total` from `movimentacoes` group by date_format(`movimentacoes`.`data_movimentacao`,'%Y-%m') order by date_format(`movimentacoes`.`data_movimentacao`,'%Y-%m') */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_equipamentos_por_setor`
--

/*!50001 DROP VIEW IF EXISTS `vw_equipamentos_por_setor`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = cp850 */;
/*!50001 SET character_set_results     = cp850 */;
/*!50001 SET collation_connection      = cp850_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_equipamentos_por_setor` AS select `s`.`nome` AS `setor`,count(`m`.`id`) AS `total_movimentacoes`,coalesce(sum(`m`.`quantidade`),0) AS `total_equipamentos` from (`setores` `s` left join `movimentacoes` `m` on(`m`.`setor_id` = `s`.`id`)) group by `s`.`id`,`s`.`nome` order by coalesce(sum(`m`.`quantidade`),0) desc,`s`.`nome` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_movimentacoes_por_tipo`
--

/*!50001 DROP VIEW IF EXISTS `vw_movimentacoes_por_tipo`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = cp850 */;
/*!50001 SET character_set_results     = cp850 */;
/*!50001 SET collation_connection      = cp850_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_movimentacoes_por_tipo` AS select `movimentacoes`.`tipo` AS `tipo`,count(0) AS `total`,round(count(0) * 100 / nullif((select count(0) from `movimentacoes`),0),1) AS `percentual` from `movimentacoes` group by `movimentacoes`.`tipo` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-06-23  7:38:00
