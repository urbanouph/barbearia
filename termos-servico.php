<?php
// Rascunho informativo. Revise as regras comerciais reais antes da publicação.
require_once __DIR__ . '/config/database.php';
?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Termos de Serviço | Shift Barber</title>
  <meta name="description" content="Termos para uso do sistema de conta e agendamento da Shift Barber.">
  <link rel="icon" type="image/png" href="assets/logo.png">
  <style>
    :root{color-scheme:dark;--bg:#09090b;--panel:#18181b;--line:#3f3f46;--text:#f4f4f5;--muted:#a1a1aa;--gold:#f59e0b}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:16px/1.7 Arial,sans-serif}.wrap{max-width:850px;margin:0 auto;padding:28px 20px 64px}.top{display:flex;align-items:center;gap:14px;border-bottom:1px solid var(--line);padding-bottom:18px}.top img{width:48px;height:48px;object-fit:contain}.top a,.back{color:var(--gold);text-decoration:none}h1{font-size:clamp(28px,5vw,40px);line-height:1.15;margin:32px 0 8px}h2{font-size:21px;margin:30px 0 8px}p,li{color:#d4d4d8}.notice{background:#272015;border:1px solid #78520b;border-radius:12px;padding:14px 16px;color:#fef3c7;margin:22px 0}.card{background:var(--panel);border:1px solid #27272a;border-radius:16px;padding:20px;margin-top:22px}.muted,small{color:var(--muted)}a{overflow-wrap:anywhere}footer{border-top:1px solid var(--line);margin-top:36px;padding-top:18px}
  </style>
</head>
<body><main class="wrap">
  <div class="top"><img src="assets/logo.png" alt="Logotipo da Shift Barber"><a href="cliente_login.php">Voltar à área do cliente</a></div>
  <h1>Termos de Serviço</h1>
  <p class="muted">Última atualização: 8 de novembro de 2026</p>
  <div class="notice"><strong>Importante:</strong> este é um modelo inicial, não uma revisão jurídica. Preencha os campos indicados e confirme as regras reais de agendamento, cancelamento, atraso, preços e pagamento antes de publicar.</div>
  <section class="card">
    <h2>1. Identificação e aceitação</h2>
    <p>O serviço online é disponibilizado pela <strong>[NOME EMPRESARIAL OU NOME DO RESPONSÁVEL]</strong>, responsável pela Shift Barber, CNPJ/CPF <strong>[CNPJ OU CPF]</strong>, endereço <strong>[ENDEREÇO COMERCIAL]</strong> e contato <strong>[E-MAIL OU TELEFONE DE CONTATO]</strong>. Ao criar uma conta ou usar os recursos de agendamento, você declara que leu estes termos.</p>
    <h2>2. Uso da conta</h2>
    <p>Forneça informações corretas e atualizadas e mantenha suas credenciais em segurança. Você é responsável pelas atividades realizadas em sua conta e deve avisar a barbearia se suspeitar de acesso não autorizado. A conta é pessoal; não use o sistema para fraude, violação de direitos ou interferência no funcionamento do serviço.</p>
    <h2>3. Agendamentos</h2>
    <p>O sistema permite solicitar horários para os serviços disponibilizados. Um pedido só deve ser considerado confirmado quando o sistema ou a barbearia apresentar confirmação. A disponibilidade pode mudar e a barbearia poderá entrar em contato para esclarecer ou ajustar um pedido, conforme as regras informadas ao cliente.</p>
    <p><strong>Política de cancelamento, remarcação e atrasos:</strong> [DESCREVA AQUI O PRAZO PARA CANCELAR/REMARCAR, COMO FAZER, O QUE ACONTECE EM CASO DE ATRASO OU NÃO COMPARECIMENTO E SE HÁ TAXAS].</p>
    <h2>4. Serviços e valores</h2>
    <p>Os serviços, durações e valores exibidos no sistema podem ser atualizados. O valor aplicável e eventuais condições adicionais devem ser informados antes da contratação. Não acrescente cobranças não informadas ao cliente.</p>
    <h2>5. Pagamentos e pacotes</h2>
    <p>Quando aplicável, a forma, o momento e as condições de pagamento serão informados pela barbearia. <strong>Regras de pagamento, validade, uso, cancelamento e reembolso de pacotes:</strong> [INFORME AS REGRAS REAIS OU REMOVA ESTA SEÇÃO SE NÃO HOUVER PACOTES].</p>
    <h2>6. Login de terceiros</h2>
    <p>O acesso por Google é opcional e depende da disponibilidade e das regras do próprio Google. A Shift Barber não controla a disponibilidade desse serviço externo. O usuário também pode usar outra forma de acesso oferecida pelo sistema.</p>
    <h2>7. Disponibilidade e alterações</h2>
    <p>Buscamos manter o sistema disponível, mas podem ocorrer interrupções para manutenção, falhas técnicas ou situações fora do nosso controle. Estes termos podem ser atualizados; a versão vigente será publicada nesta página. Mudanças relevantes devem ser comunicadas de forma adequada.</p>
    <h2>8. Privacidade</h2>
    <p>O tratamento de dados pessoais é descrito na <a class="back" href="politica-privacidade.php">Política de Privacidade</a>, que integra as informações de uso do sistema.</p>
    <h2>9. Lei aplicável e contato</h2>
    <p>Estes termos serão interpretados conforme a legislação aplicável no Brasil, sem afastar os direitos obrigatórios do consumidor. Para suporte ou dúvidas, entre em contato por <strong>[E-MAIL OU TELEFONE DE CONTATO]</strong>.</p>
  </section>
  <footer><a class="back" href="cliente_login.php">Voltar à área do cliente</a> <span class="muted">·</span> <a class="back" href="politica-privacidade.php">Política de Privacidade</a></footer>
</main></body></html>
