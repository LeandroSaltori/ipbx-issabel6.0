/**
 * IPbx Prisma Telecom - Universal Report Hover Summary Tooltip System
 * Exibe um resumo flutuante elegante ao passar o mouse sobre qualquer linha de relatório,
 * SUPRIMINDO inteligentemente o card quando o mouse está sobre o campo de Contato/Origem ou elementos com tooltip próprio.
 */
(function() {
    function initPrismaReportHover() {
        if (typeof $ === 'undefined') return;

        var tooltip = document.getElementById('prisma_report_tooltip');
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.id = 'prisma_report_tooltip';
            document.body.appendChild(tooltip);
        }

        var currentMousePos = null;
        var hoverTimer = null;
        var pendingRow = null;
        var pendingEvent = null;
        var HOVER_DELAY = 1200; // Delay suave

        function isOverSpecificTooltip(e) {
            if (!e || !e.target) return false;
            var el = e.target;
            // Se o mouse estiver sobre o contato, ramal, botão de agenda ou qualquer elemento com title próprio
            if (el.closest('[title]') || el.closest('.contact-badge') || el.closest('button') || el.closest('a')) {
                return true;
            }
            return false;
        }

        function hideTooltip() {
            if (hoverTimer) {
                clearTimeout(hoverTimer);
                hoverTimer = null;
            }
            if (tooltip) {
                tooltip.style.opacity = '0';
                tooltip.style.display = 'none';
            }
        }

        function positionTooltip(e) {
            if (!e || !tooltip) return;

            var pageX = e.pageX;
            var pageY = e.pageY;

            if (typeof pageX === 'undefined' || pageX === null) {
                pageX = e.clientX + (window.pageXOffset || document.documentElement.scrollLeft || document.body.scrollLeft || 0);
                pageY = e.clientY + (window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0);
            }

            var clientX = e.clientX;
            var clientY = e.clientY;

            var tooltipWidth = tooltip.offsetWidth || 280;
            var tooltipHeight = tooltip.offsetHeight || 160;

            var x = pageX + 18;
            var y = pageY + 18;

            // Ajusta se ultrapassar a borda direita da tela
            if (clientX + tooltipWidth + 25 > window.innerWidth) {
                x = pageX - tooltipWidth - 15;
            }

            // Ajusta se ultrapassar a borda inferior da tela
            if (clientY + tooltipHeight + 25 > window.innerHeight) {
                y = pageY - tooltipHeight - 15;
            }

            tooltip.style.setProperty('position', 'absolute', 'important');
            tooltip.style.setProperty('left', Math.max(10, x) + 'px', 'important');
            tooltip.style.setProperty('top', Math.max(10, y) + 'px', 'important');
            tooltip.style.setProperty('z-index', '999999', 'important');
            tooltip.style.setProperty('pointer-events', 'none', 'important');
            tooltip.style.setProperty('margin', '0', 'important');
            tooltip.style.setProperty('transform', 'none', 'important');
            tooltip.style.setProperty('display', 'block', 'important');
            tooltip.style.setProperty('background', 'linear-gradient(135deg, rgba(30, 20, 53, 0.96), rgba(45, 27, 78, 0.98))', 'important');
            tooltip.style.setProperty('border', '1px solid rgba(168, 85, 247, 0.5)', 'important');
            tooltip.style.setProperty('border-radius', '10px', 'important');
            tooltip.style.setProperty('padding', '12px 16px', 'important');
            tooltip.style.setProperty('box-shadow', '0 10px 25px rgba(0, 0, 0, 0.5), 0 0 15px rgba(168, 85, 247, 0.25)', 'important');
            tooltip.style.setProperty('color', '#ffffff', 'important');
            tooltip.style.setProperty('font-family', '"Noto Sans", sans-serif, Arial', 'important');
            tooltip.style.setProperty('font-size', '12px', 'important');
            tooltip.style.setProperty('min-width', '240px', 'important');
            tooltip.style.setProperty('max-width', '360px', 'important');
            tooltip.style.setProperty('backdrop-filter', 'blur(8px)', 'important');
            tooltip.style.setProperty('transition', 'opacity 0.15s ease', 'important');
        }

        function renderRowTooltip($row, e) {
            if (isOverSpecificTooltip(e)) {
                hideTooltip();
                return;
            }

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
        }

        $(document).on('mouseenter', 'table tr, .table tr', function(e) {
            var $row = $(this);
            if ($row.find('th').length > 0 || $row.parent().is('thead')) return;

            if (isOverSpecificTooltip(e)) {
                hideTooltip();
                return;
            }

            if (hoverTimer) clearTimeout(hoverTimer);
            pendingRow = $row;
            pendingEvent = e;

            hoverTimer = setTimeout(function() {
                if (pendingRow && pendingEvent) {
                    renderRowTooltip(pendingRow, pendingEvent);
                }
            }, HOVER_DELAY);
        });

        $(document).on('mousemove', 'table tr, .table tr', function(e) {
            if (isOverSpecificTooltip(e)) {
                hideTooltip();
                return;
            }

            pendingEvent = e;
            currentMousePos = e;
            if (tooltip && tooltip.style.display !== 'none') {
                positionTooltip(e);
            }
        });

        $(document).on('mouseleave', 'table tr, .table tr', function() {
            hideTooltip();
            pendingRow = null;
            pendingEvent = null;
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
