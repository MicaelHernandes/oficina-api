"""
Gera o PDF de entrega da Fase 4 (Tech Challenge SOAT — Oficina Mecânica).

Preencha PARTICIPANTES e VIDEO_URL abaixo e rode (precisa de reportlab e da
fonte DejaVu):

    docker run --rm -v "$PWD":/w -w /w python:3.12-slim sh -c \
      "apt-get update -qq && apt-get install -y -qq fonts-dejavu-core fonts-dejavu-extra >/dev/null \
       && pip install -q reportlab && python docs/entrega/gerar_pdf.py"

Saída: docs/entrega/Fase4-TechChallenge-Oficina.pdf
"""

from datetime import date
from pathlib import Path

from reportlab.graphics.shapes import Drawing, Line, Polygon, Rect, String
from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import cm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    KeepTogether,
    PageBreak,
    Paragraph,
    SimpleDocTemplate,
    Spacer,
    Table,
    TableStyle,
)

# ── Preencher ────────────────────────────────────────────────────────────────
PARTICIPANTES = [
    # (nome, RM, usuário do GitHub)
    ("[Nome do participante]", "[RM]", "[github]"),
    ("[Nome do participante]", "[RM]", "[github]"),
    ("[Nome do participante]", "[RM]", "[github]"),
]
VIDEO_URL = "[link do vídeo no YouTube/Vimeo — até 15 minutos]"
# ─────────────────────────────────────────────────────────────────────────────

GITHUB = "https://github.com/MicaelHernandes"
REPOS = [
    ("oficina-api", "OS Service — clientes, veículos, catálogo, estoque e OS; orquestrador da saga"),
    ("oficina-billing-service", "Billing Service — orçamento e pagamento (Mercado Pago)"),
    ("oficina-execution-service", "Execution Service — fila de execução, diagnóstico e reparo"),
    ("oficina-k8s-infra", "Infraestrutura base: VPC, EKS, ECR, ALB, DNS/TLS, observabilidade, OIDC"),
    ("oficina-db-infra", "RDS PostgreSQL e credenciais por serviço"),
    ("oficina-auth-lambda", "Autenticação do cliente por CPF (Lambda + API Gateway)"),
]

OUT = Path(__file__).with_name("Fase4-TechChallenge-Oficina.pdf")

FONT_DIR = Path("/usr/share/fonts/truetype/dejavu")
pdfmetrics.registerFont(TTFont("Sans", FONT_DIR / "DejaVuSans.ttf"))
pdfmetrics.registerFont(TTFont("Sans-Bold", FONT_DIR / "DejaVuSans-Bold.ttf"))
pdfmetrics.registerFont(TTFont("Sans-Oblique", FONT_DIR / "DejaVuSans-Oblique.ttf"))
pdfmetrics.registerFontFamily("Sans", normal="Sans", bold="Sans-Bold", italic="Sans-Oblique")

INK = colors.HexColor("#1F2933")
MUTED = colors.HexColor("#52606D")
ACCENT = colors.HexColor("#1D4E89")
RULE = colors.HexColor("#CBD2D9")
ZEBRA = colors.HexColor("#F5F7FA")

# Paleta do diagrama (fundo claro, borda mais escura do mesmo tom).
C_SERVICE = (colors.HexColor("#E3EEF9"), colors.HexColor("#1D4E89"))
C_DATA = (colors.HexColor("#FDF1DC"), colors.HexColor("#A86A12"))
C_MSG = (colors.HexColor("#E4F4EA"), colors.HexColor("#2F7A4B"))
C_EDGE = (colors.HexColor("#EFEAF8"), colors.HexColor("#5B3E96"))
C_EXT = (colors.HexColor("#F1F3F5"), colors.HexColor("#616E7C"))
CMD = colors.HexColor("#1D4E89")
EVT = colors.HexColor("#2F7A4B")

H1 = ParagraphStyle("h1", fontName="Sans-Bold", fontSize=15, leading=19, textColor=ACCENT, spaceBefore=10, spaceAfter=8, keepWithNext=1)
H2 = ParagraphStyle("h2", fontName="Sans-Bold", fontSize=11.5, leading=15, textColor=INK, spaceBefore=10, spaceAfter=4, keepWithNext=1)
BODY = ParagraphStyle("body", fontName="Sans", fontSize=9.3, leading=13.2, textColor=INK, spaceAfter=5)
SMALL = ParagraphStyle("small", parent=BODY, fontSize=8.2, leading=11, textColor=MUTED)
CELL = ParagraphStyle("cell", parent=BODY, fontSize=8.4, leading=11.2, spaceAfter=0)
CELL_B = ParagraphStyle("cellb", parent=CELL, fontName="Sans-Bold")
BULLET = ParagraphStyle("bullet", parent=BODY, leftIndent=12, bulletIndent=2, spaceAfter=2.5)
TITLE = ParagraphStyle("title", fontName="Sans-Bold", fontSize=21, leading=26, textColor=ACCENT, alignment=TA_CENTER)
SUB = ParagraphStyle("sub", fontName="Sans", fontSize=11, leading=15, textColor=MUTED, alignment=TA_CENTER)


def p(text, style=BODY):
    return Paragraph(text, style)


def bullets(items):
    return [Paragraph(i, BULLET, bulletText="•") for i in items]


def table(rows, widths, header=True):
    data = [[c if not isinstance(c, str) else Paragraph(c, CELL_B if (header and r == 0) else CELL) for c in row]
            for r, row in enumerate(rows)]
    t = Table(data, colWidths=widths, repeatRows=1 if header else 0)
    style = [
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LINEBELOW", (0, 0), (-1, -1), 0.4, RULE),
        ("TOPPADDING", (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
        ("LEFTPADDING", (0, 0), (-1, -1), 5),
        ("RIGHTPADDING", (0, 0), (-1, -1), 5),
    ]
    if header:
        style += [("BACKGROUND", (0, 0), (-1, 0), ZEBRA), ("LINEBELOW", (0, 0), (-1, 0), 0.8, MUTED)]
    t.setStyle(TableStyle(style))
    return t


# ── Diagrama ────────────────────────────────────────────────────────────────
def box(d, x, y, w, h, palette, title, lines=(), title_size=7.6, line_size=6.3):
    fill, stroke = palette
    d.add(Rect(x, y, w, h, rx=4, ry=4, fillColor=fill, strokeColor=stroke, strokeWidth=0.9))
    # Sem linhas de detalhe, o título fica centralizado na vertical.
    cy = y + h - 11 if lines else y + h / 2 - title_size / 3
    d.add(String(x + w / 2, cy, title, fontName="Sans-Bold", fontSize=title_size, fillColor=stroke, textAnchor="middle"))
    for i, line in enumerate(lines):
        d.add(String(x + w / 2, cy - 9.5 - i * 8.2, line, fontName="Sans", fontSize=line_size, fillColor=INK, textAnchor="middle"))


def arrow(d, x1, y1, x2, y2, color=MUTED, width=0.9, dash=None, label=None, label_dx=0, label_dy=0, label_anchor="middle"):
    d.add(Line(x1, y1, x2, y2, strokeColor=color, strokeWidth=width, strokeDashArray=dash))
    # Ponta da seta.
    import math

    ang = math.atan2(y2 - y1, x2 - x1)
    size = 4.2
    left = (x2 - size * math.cos(ang - 0.42), y2 - size * math.sin(ang - 0.42))
    right = (x2 - size * math.cos(ang + 0.42), y2 - size * math.sin(ang + 0.42))
    d.add(Polygon([x2, y2, *left, *right], fillColor=color, strokeColor=color, strokeWidth=0.3))
    if label:
        mx, my = (x1 + x2) / 2 + label_dx, (y1 + y2) / 2 + label_dy
        d.add(String(mx, my, label, fontName="Sans", fontSize=5.8, fillColor=color, textAnchor=label_anchor))


def path(d, points, color, width=0.9, dash=None):
    """Polilinha com seta no último segmento."""
    for (x1, y1), (x2, y2) in zip(points, points[1:-1]):
        d.add(Line(x1, y1, x2, y2, strokeColor=color, strokeWidth=width, strokeDashArray=dash))
    (x1, y1), (x2, y2) = points[-2], points[-1]
    arrow(d, x1, y1, x2, y2, color=color, width=width, dash=dash)


def fit(d, width):
    """Escala o desenho para caber na largura útil da página."""
    f = width / d.width
    d.scale(f, f)
    d.width, d.height = d.width * f, d.height * f
    return d


def architecture():
    W, H = 520, 572
    d = Drawing(W, H)
    cols = [15, 180, 345]  # início de cada coluna de serviço (160 de largura)
    cw = 160

    # Externos
    box(d, 15, 522, 150, 40, C_EXT, "Cliente", ["navegador · e-mail com links assinados"])
    box(d, 185, 522, 150, 40, C_EXT, "Staff (atendente / mecânico)", ["Postman · painel"])
    box(d, 355, 522, 150, 40, C_EXT, "Mercado Pago", ["Checkout Pro · webhook"])

    # Borda (Cloudflare DNS + ACM → API Gateway / ALB)
    box(d, 15, 446, 150, 48, C_EDGE, "API Gateway + Lambda", ["api.codefive.com.br", "POST /auth (CPF → JWT)"])
    box(d, 185, 446, 150, 48, C_EDGE, "ALB compartilhado (TLS)", ["app.codefive.com.br", "/api · /billing · /execution"])
    arrow(d, 90, 522, 90, 494)
    arrow(d, 260, 522, 260, 494)
    arrow(d, 150, 522, 215, 494, dash=[2, 2], label="links do e-mail", label_dx=-14, label_dy=2, label_anchor="end")
    arrow(d, 165, 470, 185, 470, label="proxy", label_dy=4)
    arrow(d, 385, 522, 337, 476, dash=[2, 2], color=C_EXT[1], label="webhook", label_dx=-6, label_dy=6, label_anchor="end")

    # Cluster EKS
    d.add(Rect(6, 304, 508, 110, rx=6, ry=6, fillColor=colors.white, strokeColor=ACCENT, strokeWidth=1.1, strokeDashArray=[4, 2]))
    d.add(String(12, 404, "Amazon EKS · ns oficina", fontName="Sans-Bold", fontSize=6.6, fillColor=ACCENT))
    d.add(String(508, 404, "Prometheus · Grafana · Loki", fontName="Sans", fontSize=6.0, fillColor=MUTED, textAnchor="end"))

    services = [
        ("OS Service", ["oficina-api · orquestrador da saga", "clientes, veículos, catálogo, estoque, OS",
                        "saga_instances · outbox · inbox", "worker: saga:relay + saga:consume",
                        "scheduler: saga:timeouts"]),
        ("Billing Service", ["oficina-billing-service", "orçamento (revisões) e links assinados",
                             "cobrança, webhook e estorno (MP)", "worker: billing:relay + consume",
                             "inbox + estado + outbox atômicos"]),
        ("Execution Service", ["oficina-execution-service", "fila de execução por prioridade",
                               "diagnóstico → reparo → fim / falha", "worker: execution:relay + consume",
                               "linha do tempo da execução"]),
    ]
    for c, (title, lines) in zip(cols, services):
        box(d, c, 314, cw, 80, C_SERVICE, title, lines + ["Laravel 13 · php-fpm + nginx · HPA"], title_size=8.2, line_size=6.2)
        arrow(d, 260, 446, c + cw / 2, 394, color=ACCENT, width=0.8)

    arrow(d, cols[1] + 140, 394, 425, 522, dash=[3, 2], color=C_EXT[1], label="API: preferência, consulta, estorno", label_dx=14, label_dy=4, label_anchor="start")

    # Acesso de cada serviço ao próprio banco (desenhado antes, para ficar por baixo).
    for c in cols:
        d.add(Line(c + 12, 314, c + 12, 142, strokeColor=C_DATA[1], strokeWidth=0.9, strokeDashArray=[1.5, 1.5]))

    # Mensageria (SNS/SQS gerenciados)
    T, Q = 248, 180  # base dos tópicos e das filas
    topics = ["oficina-os-events", "oficina-billing-events", "oficina-execution-events"]
    queues = ["oficina-os-service", "oficina-billing-service", "oficina-execution-service"]
    for c, t, q in zip(cols, topics, queues):
        box(d, c + 28, T, 86, 34, C_MSG, "SNS", [t], title_size=6.8, line_size=5.6)
        box(d, c + 28, Q, 128, 32, C_MSG, "SQS + DLQ", [q], title_size=6.8, line_size=5.6)
        arrow(d, c + 71, 314, c + 71, T + 34, color=EVT, width=0.8, label="publica", label_dx=-3, label_dy=-6, label_anchor="end")
        arrow(d, c + 145, Q + 32, c + 145, 314, color=EVT, width=0.8, label="consome", label_dx=-3, label_dy=26, label_anchor="end")

    # Faixa de comandos (OS → Billing/Execution) e de eventos (Billing/Execution → OS).
    lane_cmd, lane_evt = T - 10, T - 22
    path(d, [(cols[0] + 100, T), (cols[0] + 100, lane_cmd), (cols[1] + 60, lane_cmd), (cols[1] + 60, Q + 32)], CMD)
    path(d, [(cols[1] + 60, lane_cmd), (cols[2] + 60, lane_cmd), (cols[2] + 60, Q + 32)], CMD)
    path(d, [(cols[1] + 40, T), (cols[1] + 40, lane_evt), (cols[0] + 55, lane_evt), (cols[0] + 55, Q + 32)], EVT, dash=[3, 1.5])
    path(d, [(cols[2] + 40, T), (cols[2] + 40, lane_evt), (cols[1] + 40, lane_evt)], EVT, dash=[3, 1.5])
    d.add(String(cols[2] + 112, lane_cmd + 2.5, "comandos billing.* / execution.*", fontName="Sans", fontSize=5.6, fillColor=CMD, textAnchor="end"))
    d.add(String(cols[1] - 8, lane_evt - 7, "eventos → orquestrador", fontName="Sans", fontSize=5.6, fillColor=EVT, textAnchor="middle"))
    caption = "Mensageria AWS: tópico por serviço, fila + DLQ por consumidor, filter policy pelo atributo type"
    cwid = pdfmetrics.stringWidth(caption, "Sans-Bold", 6.4)
    d.add(Rect(W / 2 - cwid / 2 - 4, Q - 16, cwid + 8, 11, fillColor=colors.white, strokeColor=None))
    d.add(String(W / 2, Q - 12, caption, fontName="Sans-Bold", fontSize=6.4, fillColor=C_MSG[1], textAnchor="middle"))

    # Bancos
    box(d, cols[0], 96, cw, 46, C_DATA, "RDS PostgreSQL 16", ["database oficina · usuário master", "OS Service (+ Lambda de auth)"])
    box(d, cols[1], 96, cw, 46, C_DATA, "Amazon DynamoDB", ["tabela única oficina-billing", "GSI por OS · outbox esparso · TTL"])
    box(d, cols[2], 96, cw, 46, C_DATA, "RDS PostgreSQL 16", ["database execution", "usuário execution_svc (isolado)"])
    d.add(String(W / 2, 84, "Mesma instância db.t4g.micro para os dois PostgreSQL, com databases e usuários separados: nenhum serviço acessa o banco de outro.",
                 fontName="Sans", fontSize=6.0, fillColor=MUTED, textAnchor="middle"))

    # Legenda
    ly = 58
    items = [(CMD, None, "comando do orquestrador"), (EVT, [3, 1.5], "evento de um serviço"),
             (ACCENT, None, "HTTP via ALB"), (C_DATA[1], [1.5, 1.5], "acesso ao banco próprio"),
             (C_EXT[1], [3, 2], "Mercado Pago")]
    total = sum(22 + pdfmetrics.stringWidth(label, "Sans", 6.2) + 14 for *_, label in items) - 14
    x = (W - total) / 2
    for color, dash, label in items:
        d.add(Line(x, ly, x + 18, ly, strokeColor=color, strokeWidth=1.1, strokeDashArray=dash))
        d.add(String(x + 22, ly - 2, label, fontName="Sans", fontSize=6.2, fillColor=INK))
        x += 22 + pdfmetrics.stringWidth(label, "Sans", 6.2) + 14
    return fit(d, 481)


def saga_states():
    W, H = 520, 150
    d = Drawing(W, H)
    happy = ["diagnosis", "awaiting_approval", "awaiting_payment", "awaiting_execution", "executing", "completed"]
    xs = [8 + i * 86 for i in range(len(happy))]
    for x, s in zip(xs, happy):
        box(d, x, 100, 78, 26, C_SERVICE, s, title_size=6.4)
    for a, b in zip(xs, xs[1:]):
        arrow(d, a + 78, 113, b, 113, color=ACCENT, width=0.8)
    labels = ["orçamento", "aprovado", "pago + peças", "iniciou", "finalizou"]
    for a, l in zip(xs, labels):
        d.add(String(a + 82, 130, l, fontName="Sans", fontSize=5.6, fillColor=MUTED, textAnchor="middle"))

    box(d, xs[1], 30, 78, 26, C_EXT, "cancelled", title_size=6.4)
    box(d, xs[2] + 20, 30, 78, 26, C_DATA, "compensated", title_size=6.4)
    box(d, xs[4] - 10, 30, 90, 26, C_DATA, "compensating", title_size=6.4)
    arrow(d, xs[1] + 50, 100, xs[1] + 50, 56, color=MUTED, label="recusa definitiva", label_dx=3, label_dy=4, label_anchor="start")
    arrow(d, xs[2] + 50, 100, xs[2] + 59, 56, color=C_DATA[1], label="pagamento recusado/vencido", label_dx=5, label_dy=10, label_anchor="start")
    arrow(d, xs[3] + 39, 100, xs[4] + 20, 56, color=C_DATA[1], label="sem estoque · falhou · vencido", label_dx=16, label_dy=8, label_anchor="start")
    arrow(d, xs[4] + 39, 100, xs[4] + 39, 56, color=C_DATA[1])
    arrow(d, xs[4] - 10, 43, xs[2] + 98, 43, color=C_DATA[1], label="estorno confirmado", label_dy=4)
    path(d, [(xs[1] + 14, 100), (xs[1] + 14, 86), (xs[0] + 39, 86), (xs[0] + 39, 100)], MUTED, width=0.7)
    d.add(String(xs[0] + 60, 78, "recusa: nova proposta", fontName="Sans", fontSize=5.6, fillColor=MUTED, textAnchor="middle"))
    return fit(d, 481)


# ── Documento ───────────────────────────────────────────────────────────────
def on_page(canvas, doc):
    canvas.saveState()
    canvas.setFont("Sans", 7.5)
    canvas.setFillColor(MUTED)
    canvas.drawString(2 * cm, 1.2 * cm, "Tech Challenge SOAT · Fase 4 · Oficina Mecânica")
    canvas.drawRightString(A4[0] - 2 * cm, 1.2 * cm, f"{doc.page}")
    canvas.restoreState()


def build():
    story = []

    # Capa e identificação
    story += [Spacer(1, 1.2 * cm), p("Oficina Mecânica — Fase 4", TITLE), Spacer(1, 4),
              p("Microsserviços, Saga Pattern orquestrado e CI/CD", SUB),
              p(f"Tech Challenge SOAT · {date.today().strftime('%m/%Y')}", SUB), Spacer(1, 0.9 * cm)]

    story.append(p("Participantes", H2))
    story.append(table([["Nome", "RM", "GitHub"], *[list(x) for x in PARTICIPANTES]], [8.5 * cm, 3 * cm, 5.5 * cm]))

    story.append(p("Vídeo de demonstração", H2))
    story.append(p(VIDEO_URL))
    story.append(p("Roteiro: fluxo completo de uma OS pelos três microsserviços, execução da saga e das compensações, "
                   "deploy automatizado de um microsserviço com validação de testes e rastreamento do fluxo distribuído "
                   "(correlation id no Grafana/Loki e histórico da saga).", SMALL))

    story.append(p("Repositórios", H2))
    story.append(table([["Repositório (github.com/MicaelHernandes/…)", "Papel"],
                        *[[f'<link href="{GITHUB}/{r}" color="#1D4E89">{r}</link>', d] for r, d in REPOS]],
                       [6.2 * cm, 10.8 * cm]))
    story.append(Spacer(1, 6))
    story.append(p("Cada microsserviço tem repositório, banco, infraestrutura (Terraform em <i>infra/</i>), Dockerfile, manifestos "
                   "Kubernetes, pipelines de CI/CD, Swagger e documentação próprios. A collection Postman do fluxo completo está em "
                   "<i>oficina-api/docs/postman</i>.", SMALL))
    story.append(PageBreak())

    # Diagrama
    story.append(p("1. Arquitetura geral", H1))
    story.append(p("Três microsserviços Laravel no mesmo cluster EKS, expostos pelo ALB compartilhado por prefixo de caminho. "
                   "Cada um tem banco próprio e conversa com os outros só por mensagens (SNS → SQS); a coordenação das transações "
                   "da OS é feita pela saga orquestrada no OS Service."))
    story.append(architecture())
    story.append(PageBreak())

    # Divisão
    story.append(p("2. Divisão dos microsserviços", H1))
    story.append(table([
        ["Serviço", "Responsabilidade", "Banco", "Por que separado"],
        ["<b>OS Service</b><br/>oficina-api",
         "Abertura, status e histórico da OS; clientes, veículos, catálogo e estoque; diagnóstico e montagem do orçamento; "
         "<b>orquestrador da saga</b>.",
         "PostgreSQL (database <i>oficina</i>)",
         "É o dono do ciclo de vida da OS e da máquina de estados — o lugar natural do orquestrador. Catálogo e estoque ficam "
         "junto porque a reserva de peças é um passo local da saga, consistente na mesma transação."],
        ["<b>Billing Service</b><br/>oficina-billing-<br/>service",
         "Orçamento oficial (revisões), envio ao cliente com links assinados, aprovação/recusa, cobrança no Mercado Pago, "
         "webhook e estorno.",
         "DynamoDB (tabela <i>oficina-billing</i>)",
         "Integração externa de pagamento com ciclo e falhas próprios (webhooks, reenvios, estornos). Isolada, uma "
         "instabilidade do gateway não derruba a abertura nem a execução de OS."],
        ["<b>Execution Service</b><br/>oficina-execution-<br/>service",
         "Fila de execução por prioridade; diagnóstico, reparo, anotações; comunicar fim ou falha ao OS Service.",
         "PostgreSQL (database <i>execution</i>, usuário próprio)",
         "Trabalho do chão de oficina, com escala e ritmo diferentes do atendimento e da cobrança. Pode evoluir "
         "(agenda de mecânicos, boxes) sem tocar nos outros serviços."],
    ], [3.9 * cm, 5.0 * cm, 2.9 * cm, 5.2 * cm]))
    story.append(Spacer(1, 6))
    story.append(p("<b>SQL e NoSQL:</b> o OS Service e o Execution usam PostgreSQL porque têm dados relacionais e transações locais "
                   "(estoque, fila com bloqueio de linha). O Billing usa DynamoDB: orçamento e cobrança são documentos (itens, "
                   "cliente e payload do gateway) acessados por chave ou por OS, sem joins; on-demand, sem instância nem pod no nó "
                   "único do EKS, e com transações para o padrão inbox/outbox."))
    story.append(p("<b>Isolamento dos dados:</b> para não pagar uma segunda instância RDS no Free plan, o Execution tem database e usuário "
                   "próprios na mesma instância. O Job de bootstrap revoga o CONNECT público dos dois databases: o usuário do "
                   "Execution não conecta no database do OS Service, e nenhum serviço lê o banco de outro."))

    story.append(PageBreak())
    story.append(p("3. Tecnologias e justificativas", H1))
    story.append(table([
        ["Tecnologia", "Uso", "Justificativa"],
        ["PHP 8.4 / Laravel 13 (DDD)", "Os três serviços", "Mesma stack da Fase 3: reaproveita domínio, testes, Dockerfile, Kustomize e pipelines; o time domina."],
        ["Amazon EKS 1.34 + ALB", "Execução e entrada HTTP", "Cluster existente; um ALB compartilhado roteia /api, /billing e /execution sem novo certificado."],
        ["Amazon SNS + SQS", "Mensageria da saga", "Gerenciado e sem custo fixo; DLQ nativa, filter policy por tipo, IRSA. Broker no cluster disputaria o único nó."],
        ["RDS PostgreSQL 16", "OS e Execution", "Relacional com transações e SELECT FOR UPDATE; instância já provisionada (db.t4g.micro)."],
        ["Amazon DynamoDB", "Billing", "NoSQL serverless on-demand; TransactWriteItems garante inbox + estado + outbox atômicos."],
        ["Mercado Pago (Checkout Pro)", "Pagamento", "Exigido pelo enunciado; webhook com x-signature e estorno idempotente."],
        ["Terraform + GitHub Actions (OIDC)", "Infra e CI/CD", "Cada serviço aplica a própria infra/ no deploy, sem chaves estáticas na AWS."],
        ["Pest · Behat · SonarCloud", "Qualidade", "Testes unitários e de feature com cobertura ≥ 80% no CI, BDD em Gherkin e análise estática no PR."],
        ["Prometheus · Grafana · Loki", "Observabilidade", "Stack da Fase 3; métricas de saga/outbox e logs JSON correlacionados."],
    ], [4.3 * cm, 3.4 * cm, 9.3 * cm]))

    # Saga
    story.append(p("4. Saga Pattern: estratégia orquestrada", H1))
    story.append(p("A saga de cada OS é coordenada por um <b>orquestrador central no OS Service</b> "
                   "(<i>OsSagaOrchestrator</i>). Ele mantém o estado em <i>saga_instances</i>, envia <b>comandos</b> ao Billing e "
                   "ao Execution e reage aos <b>eventos</b> que eles publicam. O pagamento é cobrado na aprovação do orçamento, "
                   "antes da execução; por isso a compensação de qualquer falha posterior é devolver as peças e estornar."))
    story.append(saga_states())
    story.append(p("<b>Por que orquestração e não coreografia:</b>", H2))
    story += bullets([
        "O fluxo da OS e todas as compensações ficam num só lugar, legíveis e testáveis (testes de feature e BDD). Na coreografia "
        "a lógica ficaria espalhada pelos três serviços e o rollback seria implícito.",
        "A OS já tinha uma máquina de estados central (<i>OsStatus</i>); a saga a estende em vez de duplicá-la.",
        "Rastreamento simples: <i>GET /api/order-services/{id}/saga</i> mostra o estado, o contexto e o histórico de cada passo.",
        "Custo aceito: o OS Service é ponto central do fluxo. Se cair, a saga pausa, mas as mensagens esperam na fila (4 dias) e o "
        "outbox retoma a publicação.",
    ])

    story.append(KeepTogether([p("Passos e compensações", H2), table([
        ["Passo", "Comando / evento", "Falha", "Compensação"],
        ["Abertura da OS", "os.order.opened", "—", "—"],
        ["Orçamento", "billing.budget.generate → billing.budget.generated", "Cliente recusa", "Nova proposta (revisão) ou OS cancelada (cancelled)"],
        ["Aprovação", "billing.budget.approved → billing.payment.request", "—", "—"],
        ["Pagamento", "billing.payment.confirmed", "Recusado ou vencido (48 h)", "Cancela orçamento/cobrança e a OS (compensated)"],
        ["Reserva de peças", "local, tudo ou nada", "Estoque insuficiente", "Estorno (billing.payment.refund) e OS cancelada"],
        ["Execução", "execution.job.enqueue → started → finished", "Falhou ou não iniciou (24 h)", "Devolve peças, estorna, OS cancelada; fecha com billing.payment.refunded"],
    ], [2.6 * cm, 5.4 * cm, 3.4 * cm, 5.6 * cm])]))

    story.append(p("Garantias de consistência", H2))
    story += bullets([
        "<b>Transactional outbox:</b> o comando/evento é gravado na mesma transação da mudança de estado e publicado no SNS depois do "
        "commit (<i>relay</i>). Nenhuma mensagem sai sem commit, e nenhum commit fica sem a sua mensagem.",
        "<b>Inbox / idempotência:</b> o SQS entrega pelo menos uma vez; cada serviço registra o id da mensagem e ignora repetições. "
        "O Billing grava inbox, estado e outbox numa única <i>TransactWriteItems</i> do DynamoDB.",
        "<b>Lock da saga</b> (SELECT … FOR UPDATE) por mensagem, e mensagens fora de ordem são ignoradas com log.",
        "<b>Timeouts</b> (<i>saga:timeouts</i>, a cada minuto) e <b>DLQ</b> após 5 tentativas para mensagens que falham sempre.",
    ])

    # Comunicação, qualidade, observabilidade
    story.append(p("5. Comunicação entre microsserviços", H1))
    story += bullets([
        "<b>Síncrona (REST/JSON):</b> só nas bordas — staff e cliente falam com cada serviço pelo ALB; o Billing chama a API do "
        "Mercado Pago e recebe o webhook. Nenhum serviço chama outro por HTTP no fluxo da saga.",
        "<b>Assíncrona (SNS → SQS):</b> cada serviço publica no próprio tópico e consome a própria fila. As filas do Billing e do "
        "Execution assinam o tópico do OS Service com filter policy (<i>billing.*</i> / <i>execution.*</i>); a fila do OS Service "
        "assina os tópicos dos dois.",
        "<b>Envelope comum:</b> id, type, saga_id, correlation_id, os_id, source, occurred_at e payload; tópicos criptografados (KMS) "
        "e acesso por IRSA, sem chaves estáticas.",
    ])

    story.append(p("6. Testes, qualidade e CI/CD", H1))
    story.append(table([
        ["Serviço", "Testes", "Cobertura", "Pipeline"],
        ["OS Service", "187 (Pest) + BDD: 5 cenários / 84 passos (Behat)", "88%", "Pint · PHPStan · Pest · Behat · Terraform · Docker → deploy EKS"],
        ["Billing", "51 (Pest, DynamoDB Local)", "97%", "Pint · PHPStan · Pest · Terraform · Docker → deploy EKS"],
        ["Execution", "23 (Pest, PostgreSQL)", "95%", "Pint · PHPStan · Pest · Terraform · Docker → deploy EKS"],
    ], [2.4 * cm, 5.4 * cm, 2.4 * cm, 6.8 * cm]))
    story.append(Spacer(1, 5))
    story += bullets([
        "<b>BDD:</b> <i>features/saga_ordem_de_servico.feature</i> (Gherkin em português) cobre o caminho feliz até a entrega, falta "
        "de estoque com estorno, falha na execução, pagamento recusado e renegociação, contra o app real (HTTP + PostgreSQL).",
        "<b>Cobertura mínima de 80%</b> verificada no CI de cada serviço (<i>--coverage --min=80</i>), com o relatório como artefato; "
        "<b>SonarCloud</b> com Quality Gate no PR.",
        "<b>Deploy:</b> merge na <i>master</i> aplica a <i>infra/</i> do serviço, publica a imagem no ECR, cria Secrets/ConfigMaps a "
        "partir do Secrets Manager/SSM, roda os Jobs de banco/migrations e aguarda o rollout. Branches protegidas: "
        "<i>homolog</i> → <i>master</i> só por PR com checagens obrigatórias.",
    ])

    story.append(p("7. Observabilidade e rastreamento", H1))
    story += bullets([
        "O <b>X-Request-Id</b> da abertura da OS vira o <b>correlation_id</b> da saga e segue em todas as mensagens e logs JSON dos "
        "três serviços: um filtro no Loki mostra o fluxo inteiro da OS.",
        "<b>Prometheus</b> (ServiceMonitor por serviço): latência por rota, sagas por estado, outbox pendente, execuções por status, "
        "tempo médio de execução e webhooks do Mercado Pago; dashboards no Grafana.",
        "Estado e histórico de cada saga na API do OS Service; linha do tempo de cada execução no Execution Service.",
    ])

    doc = SimpleDocTemplate(str(OUT), pagesize=A4, leftMargin=2 * cm, rightMargin=2 * cm, topMargin=1.8 * cm, bottomMargin=1.8 * cm,
                            title="Tech Challenge Fase 4 — Oficina Mecânica", author="Grupo Oficina", subject="Arquitetura de microsserviços e Saga Pattern")
    doc.build(story, onFirstPage=on_page, onLaterPages=on_page)
    print(f"gerado: {OUT}")


if __name__ == "__main__":
    build()
