# Luz que Ampara — versão PHP

Projeto independente em uma página autocontida:

- `index.php`: formulário de homenagem, mural, busca, casas animadas, cartão digital, telão e gravação no banco.
- `lqa_tributes.sql`: cria a tabela única usada pela página.
- `lqa_tributes_migration.sql`: migra o esquema inicial da versão PHP para a tabela e os campos atuais, preservando os registros.
- `homenagens_velario_migration.sql`: migra uma instalação que já usa os campos em português na tabela `lqa_tributes`.

## Requisitos

- PHP 8.1 ou superior.
- Extensões `PDO_MYSQL`, `mbstring`, `iconv` e `session`.
- MySQL 8.0.16 ou superior (para validar o `CHECK` de consentimento da tabela).
- HTTPS em produção.

## Instalação

1. Crie um banco de dados MySQL e importe `lqa_tributes.sql`. Se já existir uma tabela de uma versão anterior desta implementação PHP, faça um backup e execute uma única vez o script de migração correspondente ao esquema instalado: `lqa_tributes_migration.sql` para a tabela original com colunas em inglês, ou `homenagens_velario_migration.sql` para a tabela `lqa_tributes` com colunas em português.
2. Configure as credenciais do usuário MySQL nas variáveis de ambiente do PHP. O banco padrão é `luz_que_ampara`; o usuário precisa ter permissão de `SELECT` e `INSERT` na tabela `homenagens_velario`.

   - `DB_HOST` (opcional; padrão `127.0.0.1`)
   - `DB_PORT` (opcional; padrão `3306`)
   - `DB_NAME` (opcional; padrão `luz_que_ampara`)
   - `DB_USER`
   - `DB_PASSWORD`

3. Publique `index.php` no diretório servido pelo PHP. O SQL e este README podem ficar fora do diretório público.
4. Abra `index.php`. O formulário e o mural fazem parte da mesma página.

Não coloque usuário ou senha diretamente no código nem publique essas credenciais. Configure as variáveis no ambiente em que o PHP é executado (servidor web/PHP-FPM ou configuração local); definir variáveis no terminal só as disponibiliza ao servidor iniciado nesse mesmo terminal. Se alterar as variáveis, reinicie o processo PHP para que ele as receba. Em hospedagem, o host MySQL pode não ser `127.0.0.1`; use o endereço informado pelo provedor.

Também é possível testar localmente, a partir desta pasta:

```powershell
$env:DB_HOST = "127.0.0.1"
$env:DB_PORT = "3306"
$env:DB_NAME = "luz_que_ampara"
$env:DB_USER = "usuario"
$env:DB_PASSWORD = "senha"
php -S 127.0.0.1:8000
```

Acesse `http://127.0.0.1:8000/`.

## Dados e privacidade

Todos os registros de velas ficam em `homenagens_velario`. A tabela usa colunas em português, um `id` sequencial `AUTO_INCREMENT` como chave primária e um `uuid` único para identificar as requisições e os registros públicos. A coluna `forma_exibicao` usa os valores `oculto`, `completo` e `iniciais`. A tabela armazena os dados originais da homenagem e, quando houver consentimento, uma projeção pública em nome completo ou iniciais. O mural consulta apenas essa projeção e não recebe os nomes originais de homenagens privadas.

Esta versão é independente da aplicação React na raiz do repositório. Não migra nem altera automaticamente os registros da tabela `tributes` existente. O layout de `index.php` acompanha a ordem, textos, paleta, ilustração das casas, varal de homenagens e animações do velário original.
