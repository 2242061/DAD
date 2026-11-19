#!/usr/bin/env python3
"""Gera o DOCX a partir de relatorio.md, config.json e evidências locais.

Executar com o Python do runtime de documentos indicado em LEIA_ME.md.
Os recortes das capturas são feitos no layout OOXML; os PNG originais são preservados.
"""
from __future__ import annotations

import argparse
import html
import json
import re
from pathlib import Path
from zipfile import ZipFile

from docx import Document
from docx.enum.table import WD_CELL_VERTICAL_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Pt, RGBColor
from PIL import Image, ImageDraw, ImageFont

HERE = Path(__file__).resolve().parent
BLACK = RGBColor(0, 0, 0)


def architecture() -> None:
    width, height = 2200, 790
    image = Image.new('RGB', (width, height), 'white')
    draw = ImageDraw.Draw(image)
    font_path = '/System/Library/Fonts/Supplemental/Arial.ttf'
    def font(size: int):
        try:
            return ImageFont.truetype(font_path, size)
        except OSError:
            return ImageFont.load_default(size=size)
    svg = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}" viewBox="0 0 {width} {height}">', '<rect width="100%" height="100%" fill="white"/>']
    def text(x: int, y: int, value: str, size: int = 31, anchor: str = 'mm'):
        draw.text((x, y), value, font=font(size), fill='#192d40', anchor=anchor)
        svg.append(f'<text x="{x}" y="{y}" font-family="Arial,sans-serif" font-size="{size}" text-anchor="middle" dominant-baseline="middle" fill="#192d40">{html.escape(value)}</text>')
    def box(rect, lines):
        x1, y1, x2, y2 = rect
        draw.rounded_rectangle(rect, radius=16, fill='#f3f6f8', outline='#758899', width=3)
        svg.append(f'<rect x="{x1}" y="{y1}" width="{x2-x1}" height="{y2-y1}" rx="16" fill="#f3f6f8" stroke="#758899" stroke-width="3"/>')
        mid = (y1+y2)//2
        start = mid-((len(lines)-1)*49)//2
        for i, line in enumerate(lines): text((x1+x2)//2, start+i*49, line, 35 if i == 0 else 28)
    def arrow(points, dashed=False):
        for a,b in zip(points, points[1:]):
            if dashed:
                distance=((b[0]-a[0])**2+(b[1]-a[1])**2)**.5
                count=max(1,int(distance/18))
                for i in range(0,count,2):
                    t1=i/count;t2=min((i+1)/count,1)
                    draw.line((a[0]+(b[0]-a[0])*t1,a[1]+(b[1]-a[1])*t1,a[0]+(b[0]-a[0])*t2,a[1]+(b[1]-a[1])*t2),fill='#486277',width=4)
            else: draw.line([a,b],fill='#486277',width=4)
        ax,ay=points[-2];bx,by=points[-1]
        if abs(bx-ax)>=abs(by-ay): tri=[(bx,by),(bx-16 if bx>ax else bx+16,by-10),(bx-16 if bx>ax else bx+16,by+10)]
        else: tri=[(bx,by),(bx-10,by-16 if by>ay else by+16),(bx+10,by-16 if by>ay else by+16)]
        draw.polygon(tri,fill='#486277')
        pts=' '.join(f'{x},{y}' for x,y in points)
        dash=' stroke-dasharray="18 18"' if dashed else ''
        svg.append(f'<polyline points="{pts}" fill="none" stroke="#486277" stroke-width="4"{dash}/>')
        svg.append('<polygon points="'+' '.join(f'{x},{y}' for x,y in tri)+'" fill="#486277"/>')
    box((35,270,475,500), ['Navegador', 'SPA Vue e Vue Router', 'Interface do utilizador'])
    box((615,270,1035,500), ['Vite', 'Proxy de desenvolvimento', '127.0.0.1:5173'])
    box((1235,65,1680,310), ['API REST', 'Laravel', '127.0.0.1:8000'])
    box((1810,65,2170,310), ['SQLite', 'Ficheiro local', 'database.sqlite'])
    box((1235,520,1680,755), ['Tempo real', 'Node.js e Socket.IO', '127.0.0.1:3001'])
    arrow([(475,385),(615,385)])
    arrow([(1035,330),(1135,330),(1135,190),(1235,190)])
    arrow([(1680,190),(1810,190)])
    arrow([(1035,440),(1135,440),(1135,635),(1235,635)])
    arrow([(1457,310),(1457,520)])
    text(1660,410,'Avisos privados de jogada',25)
    text(1100,77,'/api e /sanctum',27)
    text(1750,145,'SQL',27)
    text(1000,710,'/socket.io',28)
    text(615,724,'Canal Socket.IO das partidas por convite',25)
    svg.append('</svg>')
    image.save(HERE/'imagens/arquitetura.png')
    (HERE/'imagens/arquitetura.svg').write_text('\n'.join(svg))


def context() -> dict[str,str]:
    config = json.loads((HERE/'config.json').read_text())
    summary = json.loads((HERE/'evidencias/resumo.json').read_text())
    env = json.loads((HERE/'evidencias/ambiente.json').read_text())
    packages = {p['name']:p['version'].lstrip('v') for p in json.loads((HERE/'evidencias/composer.json').read_text())['installed']}
    months=['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro']
    year,month,day=map(int,config['data'].split('-'))
    ctx={**config, 'data_extenso':f'{day} de {months[month-1]} de {year}',
        'testes':str(summary['tests']), 'assercoes':str(summary['assertions']),
        'laravel':packages['laravel/framework'], 'php':env['php'], 'sqlite':env['sqlite_php'],
        'vue':env['frontend']['vue'], 'vue_router':env['frontend']['vue-router'],
        'typescript':env['frontend']['typescript'], 'vite':env['frontend']['vite'],
        'node':env['node'].lstrip('v'), 'socketio':env['realtime']['socket.io']}
    titles={'WalletTest':'Carteira e pagamentos simulados','TournamentTest':'Torneios rondas e prémios','CompetitiveRankTest':'Modos e pontos competitivos','GameClockTest':'Relógios e expiração da vez','PlayerHistoryTest':'Histórico privado e estatísticas','CheckersEngineTest':'Regras das damas portuguesas','OnlineGameTest':'Salas e autorização por cor','SpectatorTest':'Espectadores e partidas públicas','PracticeGameTest':'Partida local e treino contra o bot','AccountManagementTest':'Perfil e segurança da conta','AdminAccessTest':'Acesso à administração','AuthenticationTest':'Registo e autenticação','CreateAdminTest':'Criação de administradores','EmailVerificationTest':'Confirmação do email','LocalMailTest':'Emails locais','PasswordRecoveryTest':'Recuperação da palavra-passe','UserModerationTest':'Moderação e auditoria'}
    rows=['| Grupo de testes | Testes | Asserções | Resultado |','| --- | --- | --- | --- |']
    base_tests=base_assertions=0
    for suite in summary['suites']:
        short=suite['name'].split('\\')[-1]
        if short=='ExampleTest':base_tests+=int(suite['tests']);base_assertions+=int(suite['assertions']);continue
        result='Aprovado' if int(suite['errors'])+int(suite['failures'])==0 else 'Falhou'
        rows.append(f"| {titles.get(short,short)} | {suite['tests']} | {suite['assertions']} | {result} |")
    rows.append(f'| Testes de base | {base_tests} | {base_assertions} | Aprovado |')
    rows.append(f"| Total | {summary['tests']} | {summary['assertions']} | Aprovado |")
    ctx['tabela_testes']='\n'.join(rows)
    raw=(HERE/'evidencias/testes.txt').read_text()
    line=next((l for l in raw.splitlines() if l.startswith('{"tool":"phpunit"')),None)
    if line:
        result=json.loads(line)
        ctx['resultado_testes']='```text\nComando  php artisan test --compact\nResultado  '+result['result']+'\nTestes  '+str(result['tests'])+'    Asserções  '+str(result['assertions'])+'\nTempo desta execução  '+f"{result['duration_ms']/1000:.3f}".replace('.',',')+' s\n```'
    else:ctx['resultado_testes']='O resultado completo da execução está guardado no ficheiro de evidências.'
    return ctx


def inline(paragraph, text: str) -> None:
    for part in re.split(r'(\*\*.*?\*\*|`[^`]+`)',text):
        if not part:continue
        if part.startswith('**'):
            run=paragraph.add_run(part[2:-2]);run.bold=True
        elif part.startswith('`'):
            run=paragraph.add_run(part[1:-1]);run.font.name='Courier New';run.font.size=Pt(9)
        else:paragraph.add_run(part)


def table(doc, lines: list[str]) -> None:
    records=[[c.strip() for c in line.strip().strip('|').split('|')] for line in lines]
    records=[r for r in records if not all(re.fullmatch(r'[:\- ]+',c) for c in r)]
    n=len(records[0]);t=doc.add_table(rows=0,cols=n);t.autofit=False
    widths={3:[3.35,3.6,10.05],4:[7.6,2.7,3.1,3.6]}.get(n,[17/n]*n)
    if n==3 and records[0][0]=='Tabela':widths=[4.1,7.2,5.7]
    if n==3 and records[0][0]=='Componente':widths=[3.1,5,8.9]
    if n==4 and records[0][0]=='Operação':widths=[7.1,2.9,3.5,3.5]
    if n==3 and records[0][0]=='Terminal':widths=[2.5,2.5,12]
    for col, width in zip(t.columns, widths): col.width=Cm(width)
    borders=OxmlElement('w:tblBorders')
    for edge in ['top','left','bottom','right','insideH','insideV']:
        item=OxmlElement('w:'+edge);item.set(qn('w:val'),'single');item.set(qn('w:sz'),'5');item.set(qn('w:color'),'D9D9D9');borders.append(item)
    t._tbl.tblPr.append(borders)
    for i, record in enumerate(records):
        row=t.add_row();trpr=row._tr.get_or_add_trPr();trpr.append(OxmlElement('w:cantSplit'))
        if i==0:trpr.append(OxmlElement('w:tblHeader'))
        for j,(cell,value) in enumerate(zip(row.cells,record)):
            cell.width=Cm(widths[j]);cell.vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER
            tcpr=cell._tc.get_or_add_tcPr();margins=OxmlElement('w:tcMar')
            for side in ['top','bottom','left','right']:
                edge=OxmlElement('w:'+side);edge.set(qn('w:w'),'85');edge.set(qn('w:type'),'dxa');margins.append(edge)
            tcpr.append(margins)
            shade=OxmlElement('w:shd');shade.set(qn('w:fill'),'263B4C' if i==0 else 'F3F6F8' if i%2==0 else 'FFFFFF');tcpr.append(shade)
            p=cell.paragraphs[0];p.paragraph_format.space_after=Pt(1);p.paragraph_format.space_before=Pt(1);p.paragraph_format.line_spacing=1.06
            if n==4 and j>0:p.alignment=WD_ALIGN_PARAGRAPH.CENTER
            inline(p,value)
            for r in p.runs:
                r.font.size=Pt(9.1);r.font.color.rgb=RGBColor(255,255,255) if i==0 else RGBColor(25,25,25);r.bold=i==0 or value=='Total'
    doc.add_paragraph().paragraph_format.space_after=Pt(2)


def picture(doc, caption: str, source: str) -> None:
    settings={
        'arquitetura.png':(17.0,None),
        'ranking.png':(15.5,None),
        'torneio.png':(13.2,None),
        'carteira.png':(11.5,None),
        'partida-local.png':(16.0,(260,270,1244,995)),
        'partida-online.png':(16.0,(260,340,1244,1230)),
        'historico-jogador.png':(17.0,(260,240,1244,825)),
        'login.png':(10.4,(809,286,1216,683)),
        'perfil.png':(13.2,(267,402,820,767)),
        'administracao.png':(15.5,(260,341,1244,983)),
        'historico.png':(15.5,(260,262,1244,669)),
    }
    path=HERE/source;wcm,crop=settings.get(path.name,(16,None))
    w,h=Image.open(path).size
    if crop:
        x1,y1,x2,y2=crop
        frame_h=wcm*(y2-y1)/(x2-x1)
    else:frame_h=wcm*h/w
    p=doc.add_paragraph();p.alignment=WD_ALIGN_PARAGRAPH.CENTER;p.paragraph_format.keep_with_next=True;p.paragraph_format.space_before=Pt(3);p.paragraph_format.space_after=Pt(5)
    pic=p.add_run().add_picture(str(path),width=Cm(wcm),height=Cm(frame_h))
    pic._inline.docPr.set('descr',caption)
    if crop:
        src=OxmlElement('a:srcRect')
        src.set('l',str(round(x1/w*100000)));src.set('t',str(round(y1/h*100000)))
        src.set('r',str(round((w-x2)/w*100000)));src.set('b',str(round((h-y2)/h*100000)))
        fill=pic._inline.graphic.graphicData.pic.blipFill
        fill.insert(1,src)
    cp=doc.add_paragraph(caption,style='Caption');cp.alignment=WD_ALIGN_PARAGRAPH.CENTER
    cp.paragraph_format.space_after=Pt(10)


def build() -> Path:
    architecture();ctx=context()
    source=(HERE/'relatorio.md').read_text()
    source=re.sub(r'\{\{([a-z_]+)\}\}',lambda m:ctx[m[1]],source)
    (HERE/'evidencias/texto_gerado.md').write_text(source)
    doc=Document();sec=doc.sections[0]
    sec.page_width=Cm(21);sec.page_height=Cm(29.7)
    sec.top_margin=Cm(1.9);sec.bottom_margin=Cm(1.8);sec.left_margin=Cm(2);sec.right_margin=Cm(2)
    sec.footer_distance=Cm(.8);sec.different_first_page_header_footer=True
    for name in ['Normal','Title','Subtitle','Heading 1','Heading 2','Heading 3','Caption','List Bullet']:
        st=doc.styles[name];st.font.name='Arial';st.font.color.rgb=BLACK
        rf=st.element.get_or_add_rPr();fonts=rf.find(qn('w:rFonts'))
        if fonts is not None:fonts.set(qn('w:hAnsi'),'Arial');fonts.set(qn('w:ascii'),'Arial')
    normal=doc.styles['Normal'];normal.font.size=Pt(10.3)
    normal.paragraph_format.line_spacing=1.12;normal.paragraph_format.space_after=Pt(7)
    normal.paragraph_format.widow_control=True
    for name,size in [('Title',29),('Heading 1',17),('Heading 2',12),('Heading 3',11)]:
        st=doc.styles[name];st.font.size=Pt(size);st.font.bold=True
        st.paragraph_format.keep_with_next=True;st.paragraph_format.space_before=Pt(0 if name in ['Title','Heading 1'] else 10);st.paragraph_format.space_after=Pt(10 if name!='Heading 2' else 6)
    doc.styles['Caption'].font.size=Pt(8.7);doc.styles['Caption'].font.italic=False
    doc.styles['List Bullet'].font.size=Pt(10.3)
    footer=sec.footer.paragraphs[0];footer.paragraph_format.space_after=Pt(0)
    run=footer.add_run('DAD 2026/27  |  Plataforma de damas portuguesas');run.font.size=Pt(8)
    footer.add_run(' '*9)
    field=OxmlElement('w:fldSimple');field.set(qn('w:instr'),'PAGE');footer._p.append(field)
    footer.alignment=WD_ALIGN_PARAGRAPH.RIGHT
    lines=source.splitlines();i=0;cover=True;cover_line=0;new_page=False
    while i<len(lines):
        line=lines[i].strip()
        if not line:i+=1;continue
        if line=='<!-- page -->':new_page=True;cover=False;i+=1;continue
        if line.startswith('# '):
            p=doc.add_paragraph(line[2:],style='Title');p.paragraph_format.space_before=Pt(30);i+=1;continue
        if line.startswith('## '):
            p=doc.add_paragraph(line[3:],style='Heading 1')
            p.paragraph_format.page_break_before=new_page
            new_page=False
            i+=1
            continue
        if line.startswith('### '):doc.add_paragraph(line[4:],style='Heading 2');i+=1;continue
        if line.startswith('|'):
            block=[]
            while i<len(lines) and lines[i].strip().startswith('|'):block.append(lines[i]);i+=1
            table(doc,block);continue
        if line.startswith('```'):
            i+=1
            while i<len(lines) and not lines[i].strip().startswith('```'):
                p=doc.add_paragraph();p.paragraph_format.space_after=Pt(2);p.paragraph_format.line_spacing=1.0
                run=p.add_run(lines[i]);run.font.name='Courier New';run.font.size=Pt(8.7);i+=1
            doc.add_paragraph().paragraph_format.space_after=Pt(1);i+=1;continue
        match=re.fullmatch(r'!\[(.*?)\]\((.*?)\)',line)
        if match:picture(doc,match[1],match[2]);i+=1;continue
        if line.startswith('- '):p=doc.add_paragraph(style='List Bullet');inline(p,line[2:]);i+=1;continue
        p=doc.add_paragraph();inline(p,line)
        if cover:
            cover_line+=1
            if cover_line in (1,2):
                for run in p.runs:run.font.size=Pt(14 if cover_line==1 else 12)
                p.paragraph_format.space_after=Pt(14)
            elif cover_line<=10:p.paragraph_format.space_after=Pt(8)
        i+=1
    doc.core_properties.title=ctx['titulo'];doc.core_properties.subject='Arquitetura, jogo online e administração da plataforma'
    doc.core_properties.author='Grupo de projeto DAD';doc.core_properties.keywords='DAD, damas, Laravel, Vue, utilizadores'
    # Remover bordas herdadas do estilo Title da base DOCX.
    for tree in [doc.element, doc.styles.element]:
        for border in list(tree.xpath('.//w:pBdr')):
            border.getparent().remove(border)
    dest=HERE/'Relatorio_DAD.docx';doc.save(dest)
    with ZipFile(dest) as z:
        text=z.read('word/document.xml').decode()
        assert '{{' not in text, 'Marcadores por substituir no documento.'
    print(dest)
    return dest

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.parse_args();build()
