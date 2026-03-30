import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ElementPlus from 'element-plus'
import PrePlanOrderDetailContent from './PrePlanOrderDetailContent.vue'

const buildOrder = () => ({
  order_no: 'PO-20260330-001',
  client_name: '华东客户',
  cargo_category: {
    name: '油品',
  },
  audit_status: 'rejected',
  pickup_address: '上海装货地',
  dropoff_address: '苏州卸货地',
  status: 'pending_approval',
  submitter_id: 11,
  submitter: {
    name: '提报员甲',
    account: 'submitter-a',
  },
  audited_by: 22,
  auditor: {
    account: 'auditor-b',
  },
  audited_at: '2026-03-30T08:30:00+08:00',
  pickup_contact_name: '张三',
  pickup_contact_phone: '13800000001',
  dropoff_contact_name: '李四',
  dropoff_contact_phone: '13800000002',
  expected_pickup_at: '2026-03-31T09:00:00+08:00',
  expected_delivery_at: '2026-03-31T18:00:00+08:00',
  freight_calc_scheme: 'by_trip',
  freight_unit_price: 188.5,
  audit_remark: '请补充联系人信息',
  meta: {
    freight_template_id: 9001,
    freight_template_name: '华东油品模板',
    history: [
      {
        at: '2026-03-30T08:00:00+08:00',
        action: 'customer_submit',
        operator_name: '客户提交人',
        extra: {
          version: 1,
        },
      },
      {
        at: '2026-03-30T08:20:00+08:00',
        action: 'dispatcher_audit_reject',
        operator_account: 'dispatcher-1',
        extra: {},
      },
    ],
  },
})

const mountComponent = (props = {}) => mount(PrePlanOrderDetailContent, {
  props: {
    order: buildOrder(),
    ...props,
  },
  global: {
    plugins: [ElementPlus],
  },
})

describe('PrePlanOrderDetailContent', () => {
  it('渲染基础信息与操作轨迹，并按倒序展示历史', () => {
    const wrapper = mountComponent()
    const text = wrapper.text()

    expect(text).toContain('PO-20260330-001')
    expect(text).toContain('华东客户')
    expect(text).toContain('已驳回')
    expect(text).toContain('待审核')
    expect(text).toContain('提报员甲')
    expect(text).toContain('auditor-b')
    expect(text).toContain('华东油品模板')
    expect(text).toContain('请补充联系人信息')
    expect(text).toContain('操作轨迹')
    expect(wrapper.vm.detailHistory.map((item) => item.action)).toEqual([
      'dispatcher_audit_reject',
      'customer_submit',
    ])
  })

  it('按开关渲染联系人、期望时间、运价与模板ID字段', () => {
    const wrapper = mountComponent({
      showTemplateId: true,
      showContactFields: true,
      showExpectedTimes: true,
      showFreightFields: true,
    })
    const text = wrapper.text()

    expect(text).toContain('张三 / 13800000001')
    expect(text).toContain('李四 / 13800000002')
    expect(text).toContain('2026-03-31 09:00')
    expect(text).toContain('2026-03-31 18:00')
    expect(text).toContain('按趟')
    expect(text).toContain('188.5')
    expect(text).toContain('9001')
  })

  it('驳回状态下展示版本对比与空状态提示', () => {
    const wrapper = mountComponent({
      showCompare: true,
      compareRows: [],
      compareLoading: false,
    })

    expect(wrapper.text()).toContain('驳回后版本对比')
    expect(wrapper.text()).toContain('暂无差异（可能尚未修改关键字段）')
  })

  it('无模板与无实名信息时回退到占位展示', () => {
    const order = buildOrder()
    order.submitter = null
    order.auditor = null
    order.meta = {
      history: [],
    }

    const wrapper = mountComponent({
      order,
    })
    const text = wrapper.text()

    expect(text).toContain('#11')
    expect(text).toContain('#22')
    expect(text).toContain('未命中模板')
  })
})
