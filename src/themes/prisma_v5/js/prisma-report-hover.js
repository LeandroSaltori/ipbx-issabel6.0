/**
 * IPbx Prisma Telecom - Universal Report Hover Summary Tooltip System
 * Exibe um resumo flutuante elegante ao passar o mouse sobre qualquer linha de relatório,
 * garantindo posicionamento perfeito no cursor mesmo ao rolar a página para baixo.
 */
(function() {
    function initPrismaReportHover() {
        if (typeof $ === 'undefined') return;

        // Criar o container do tooltip flutuante no topo do document.body
        var tooltip = document.getElementById('prisma_report_tooltip');
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.id = 'prisma_report_tooltip';
            if (document.body.firstChild) {
                document.body.insertBefore(tooltip, document.body.firstChild);
            } else {
                document.body.appendChild(tooltip);
            }
        }

        var currentMousePos = null;

        function positionTooltip(e) {
            if (!e || typeof e.clientX === 'undefined') return;

            var clientX = e.clientX;
            var clientY = e.clientY;

            var x = clientX + 18;
            var y = clientY + 18;

            var tooltipWidth = tooltip.offsetWidth || 280;
            var tooltipHeight = tooltip.offsetHeight || 160;

            if (x + tooltipWidth > window.innerWidth - 15) {
                x = clientX - tooltipWidth - 15;
            }
            if (y + tooltipHeight > window.innerHeight - 15) {
                y = clientY - tooltipHeight - 15;
            }

            x = Math.max(10, Math.min(x, window.innerWidth - tooltipWidth - 10));
            y = Math.max(10, Math.min(y, window.innerHeight - tooltipHeight - 10));

            var currentOpacity = tooltip.style.opacity || '1';

            tooltip.setAttribute('style', 
                'position: fixed !important; ' +
                'left: ' + x + 'px !important; ' +
                'top: ' + y + 'px !important; ' +
                'margin: 0 !important; ' +
                'transform: none !important; ' +
                'display: block !important; ' +
                'z-index: 999999 !important; ' +
                'pointer-events: none !important; ' +
                'background: linear-gradient(135deg, rgba(30, 20, 53, 0.96), rgba(45, 27, 78, 0.98)) !important; ' +
                'border: 1px solid rgba(168, 85, 247, 0.5) !important; ' +
                'border-radius: 10px !important; ' +
                'padding: 12px 16px !important; ' +
                'box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5), 0 0 15px rgba(168, 85, 247, 0.25) !important; ' +
                'color: #ffffff !important; ' +
                'font-family: "Noto Sans", sans-serif, Arial !important; ' +
                'font-size: 12px !important; ' +
                'min-width: 240px !important; ' +
                'max-width: 360px !important; ' +
                'backdrop-filter: blur(8px) !important; ' +
                'transition: opacity 0.15s ease !important; ' +
                'opacity: ' + currentOpacity + ' !important;'
            );
        }

        $(document).on('mouseenter', 'table tr, .table tr', function(e) {
            var $row = $(this);
            var $table = $row.closest('table');
            
            if ($row.find('th').length > 0 || $row.parent().is('thead')) return;

            var headers = [];
            $table.find('thead th, tr:first th').each(function() {
                var txt = $(this).text().trim();
                headers.push(txt);
            });

            var $cells = $row.children('td');
            if ($cells.length === 0) return;

            var items = [];
            $cells.each(function(idx) {
                var label = headers[idx] || ('Item ' + (idx + 1));
                var val = $(this).text().trim().replace(/\s+/g, ' ');
                
                if (!label || label === '#' || !val || val.length > 120 || label.toLowerCase() === 'opções' || label.toLowerCase() === 'options' || label.toLowerCase() === 'ações') return;
                
                items.push({ label: label, value: val });
            });

            if (items.length === 0) return;

            var itemTitle = items[0] ? items[0].value : 'Detalhes do Relatório';
            var html = '<div style="font-weight: 700; font-size: 13px; color: #c084fc; border-bottom: 1px solid rgba(168,85,247,0.3); padding-bottom: 6px; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">' +
                '<span style="background: rgba(168,85,247,0.2); padding: 2px 6px; border-radius: 4px; font-size: 10px; color: #e9d5ff;">RESUMO</span>' +
                '<span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' + itemTitle + '</span></div>';
            
            html += '<div style="display: flex; flex-direction: column; gap: 4px;">';
            for (var i = 0; i < items.length; i++) {
                html += '<div style="display: flex; justify-content: space-between; gap: 12px; font-size: 11px; line-height: 1.4;">' +
                    '<span style="color: #a78bfa; font-weight: 600;">' + items[i].label + ':</span>' +
                    '<span style="color: #f3e8ff; text-align: right; font-weight: 400; word-break: break-word;">' + items[i].value + '</span>' +
                    '</div>';
            }
            html += '</div>';

            tooltip.innerHTML = html;
            tooltip.style.opacity = '1';
            currentMousePos = e;
            positionTooltip(e);

            $row.addClass('prisma-report-row-hover');
        });

        $(document).on('mousemove', 'table tr, .table tr', function(e) {
            currentMousePos = e;
            if (tooltip && tooltip.style.display !== 'none') {
                positionTooltip(e);
            }
        });

        $(document).on('mouseleave', 'table tr, .table tr', function() {
            if (tooltip) {
                tooltip.style.opacity = '0';
                tooltip.style.display = 'none';
            }
            $(this).removeClass('prisma-report-row-hover');
        });

        $(window).on('scroll resize', function() {
            if (currentMousePos && tooltip && tooltip.style.display !== 'none') {
                positionTooltip(currentMousePos);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPrismaReportHover);
    } else {
        initPrismaReportHover();
    }
})();
