/**
 * Inline chart for the admin-chat analytics flow.
 *
 * Rendered above the assistant bubble when the agent calls
 * `showChart`. The component is statically imported by AgentSurface
 * (we tried React.lazy first; the cross-chunk React instance broke
 * hooks — see commit 4d636e4).
 *
 * Visual direction: subtle, editorial. The chart sits inside a
 * cream surface, soft border, small caption above. Grids are
 * dotted and dim so the data carries the eye. Bars have rounded
 * tops; areas use a vertical gradient; tooltips look like the
 * chat chips (rounded pill, soft shadow).
 */
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  LineChart,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import type React from 'react'
import type { ChartArtifact } from '@/hooks/useAdminChat'

// Editorial palette — saturated but not primary; sits well on
// the cream chat surface. First entry is the "hero" colour used
// for single-series charts.
const PALETTE = [
  '#5b6cff', // periwinkle
  '#10b981', // emerald
  '#f59e0b', // amber
  '#ec4899', // rose
  '#06b6d4', // cyan
  '#8b5cf6', // violet
  '#ef4444', // red
]

const AXIS_COLOR = '#9ca3af'
const GRID_COLOR = '#e5e7eb'

interface AnalyticsChartProps {
  artifact: ChartArtifact
}

function CustomTooltip({
  active,
  payload,
  label,
}: {
  active?: boolean
  payload?: Array<{ name: string; value: number | string; color: string; dataKey?: string }>
  label?: string | number
}) {
  if (!active || !payload || payload.length === 0) return null
  return (
    <div className="bb-adminchat-chart-tooltip">
      {label !== undefined && label !== '' ? (
        <div className="bb-adminchat-chart-tooltip-label">{label}</div>
      ) : null}
      {payload.map((p, i) => (
        <div key={i} className="bb-adminchat-chart-tooltip-row">
          <span className="bb-adminchat-chart-tooltip-dot" style={{ background: p.color }} />
          <span className="bb-adminchat-chart-tooltip-name">{p.name}</span>
          <span className="bb-adminchat-chart-tooltip-value">{p.value}</span>
        </div>
      ))}
    </div>
  )
}

function AnalyticsChartInner({ artifact }: AnalyticsChartProps) {
  const { chartKind, xKey, yKeys, data, title } = artifact

  if (!Array.isArray(data) || data.length === 0) {
    return (
      <div className="bb-adminchat-chart bb-adminchat-chart-empty-state">
        <div className="bb-adminchat-chart-empty-icon" aria-hidden>
          ◌
        </div>
        <div className="bb-adminchat-chart-empty-text">No data yet.</div>
      </div>
    )
  }

  // Recharts axes look noisy at small sizes — hide them entirely
  // for single-row pies and very short bar charts; the tooltip
  // carries the data.
  const compact = data.length <= 1
  const margin = { top: 10, right: 14, left: 4, bottom: 6 }

  // Auto-size: sparse data shouldn't stretch the chart edge to edge.
  // Pies always sit in a compact card; bars get a max-width tied to
  // the bucket count; time-series-ish charts (line/area, and any
  // chart with 7+ rows) take the full chat-column width because they
  // earn it.
  const widthCap =
    chartKind === 'pie'
      ? 360
      : chartKind === 'bar' && data.length <= 3
        ? 460
        : chartKind === 'bar' && data.length <= 6
          ? 620
          : null
  const height = chartKind === 'pie' ? 240 : data.length <= 3 ? 220 : 260
  // Left-aligned to match the assistant bubble column (auto on the
  // right pushes the card to the left). Pre-2.0.81 we centered the
  // capped card, which made small charts float in the middle of the
  // panel and broke the editorial left-rail rhythm.
  const cardStyle: React.CSSProperties = widthCap
    ? { maxWidth: widthCap, marginLeft: 0, marginRight: 'auto' }
    : {}

  return (
    <div className="bb-adminchat-chart" style={cardStyle}>
      {title ? <div className="bb-adminchat-chart-title">{title}</div> : null}
      <div className="bb-adminchat-chart-canvas">
        <ResponsiveContainer width="100%" height={height}>
          {chartKind === 'line' ? (
            <LineChart data={data} margin={margin}>
              <CartesianGrid stroke={GRID_COLOR} strokeDasharray="2 6" vertical={false} />
              <XAxis
                dataKey={xKey}
                stroke={AXIS_COLOR}
                fontSize={11}
                tickLine={false}
                axisLine={false}
                tickMargin={8}
              />
              <YAxis
                stroke={AXIS_COLOR}
                fontSize={11}
                tickLine={false}
                axisLine={false}
                width={32}
              />
              <Tooltip content={<CustomTooltip />} cursor={{ stroke: GRID_COLOR }} />
              {yKeys.length > 1 ? <Legend wrapperStyle={{ fontSize: 12, paddingTop: 8 }} /> : null}
              {yKeys.map((k, i) => (
                <Line
                  key={k}
                  type="monotone"
                  dataKey={k}
                  stroke={PALETTE[i % PALETTE.length]}
                  strokeWidth={2.5}
                  dot={
                    data.length <= 30
                      ? { r: 3, strokeWidth: 0, fill: PALETTE[i % PALETTE.length] }
                      : false
                  }
                  activeDot={{ r: 5, strokeWidth: 2, stroke: '#fff' }}
                  isAnimationActive={false}
                />
              ))}
            </LineChart>
          ) : chartKind === 'area' ? (
            <AreaChart data={data} margin={margin}>
              <defs>
                {yKeys.map((k, i) => {
                  const c = PALETTE[i % PALETTE.length]
                  return (
                    <linearGradient key={k} id={`bb-area-grad-${i}`} x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor={c} stopOpacity={0.35} />
                      <stop offset="100%" stopColor={c} stopOpacity={0.02} />
                    </linearGradient>
                  )
                })}
              </defs>
              <CartesianGrid stroke={GRID_COLOR} strokeDasharray="2 6" vertical={false} />
              <XAxis
                dataKey={xKey}
                stroke={AXIS_COLOR}
                fontSize={11}
                tickLine={false}
                axisLine={false}
                tickMargin={8}
              />
              <YAxis
                stroke={AXIS_COLOR}
                fontSize={11}
                tickLine={false}
                axisLine={false}
                width={32}
              />
              <Tooltip content={<CustomTooltip />} cursor={{ stroke: GRID_COLOR }} />
              {yKeys.length > 1 ? <Legend wrapperStyle={{ fontSize: 12, paddingTop: 8 }} /> : null}
              {yKeys.map((k, i) => (
                <Area
                  key={k}
                  type="monotone"
                  dataKey={k}
                  stackId="1"
                  stroke={PALETTE[i % PALETTE.length]}
                  strokeWidth={2}
                  fill={`url(#bb-area-grad-${i})`}
                  isAnimationActive={false}
                />
              ))}
            </AreaChart>
          ) : chartKind === 'bar' ? (
            <BarChart data={data} margin={margin} barCategoryGap="22%">
              <CartesianGrid stroke={GRID_COLOR} strokeDasharray="2 6" vertical={false} />
              <XAxis
                dataKey={xKey}
                stroke={AXIS_COLOR}
                fontSize={11}
                tickLine={false}
                axisLine={false}
                tickMargin={8}
                hide={compact}
              />
              <YAxis
                stroke={AXIS_COLOR}
                fontSize={11}
                tickLine={false}
                axisLine={false}
                width={32}
              />
              <Tooltip content={<CustomTooltip />} cursor={{ fill: 'rgba(91, 108, 255, 0.06)' }} />
              {yKeys.length > 1 ? <Legend wrapperStyle={{ fontSize: 12, paddingTop: 8 }} /> : null}
              {yKeys.map((k, i) => (
                <Bar
                  key={k}
                  dataKey={k}
                  // Single-series bar → cycle colours per cell so
                  // categorical breakdowns look like a pie laid out
                  // horizontally (each bucket gets its own colour).
                  // Multi-series → solid colour per series, palette
                  // distinguishes series.
                  fill={PALETTE[i % PALETTE.length]}
                  radius={[6, 6, 0, 0]}
                  isAnimationActive={false}
                >
                  {yKeys.length === 1
                    ? data.map((_, idx) => (
                        <Cell key={idx} fill={PALETTE[idx % PALETTE.length]} />
                      ))
                    : null}
                </Bar>
              ))}
            </BarChart>
          ) : (
            // pie
            <PieChart>
              <Tooltip content={<CustomTooltip />} />
              <Pie
                data={data}
                dataKey={yKeys[0]}
                nameKey={xKey}
                cx="50%"
                cy="50%"
                innerRadius={55}
                outerRadius={95}
                paddingAngle={2}
                stroke="#fff"
                strokeWidth={2}
                isAnimationActive={false}
              >
                {data.map((_, i) => (
                  <Cell key={i} fill={PALETTE[i % PALETTE.length]} />
                ))}
              </Pie>
              <Legend
                wrapperStyle={{ fontSize: 12, paddingTop: 8 }}
                iconType="circle"
                iconSize={8}
              />
            </PieChart>
          )}
        </ResponsiveContainer>
      </div>
    </div>
  )
}

export default AnalyticsChartInner
