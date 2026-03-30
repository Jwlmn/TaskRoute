import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ElementPlus from 'element-plus'
import CustomerPrePlanOrderView from './CustomerPrePlanOrderView.vue'

const {
  getMock,
  postMock,
  messageErrorMock,
  messageSuccessMock,
} = vi.hoisted(() => ({
  getMock: vi.fn(),
  postMock: vi.fn(),
  messageErrorMock: vi.fn(),
  messageSuccessMock: vi.fn(),
}))

vi.mock('../../services/api', () => ({
  default: {
    get: getMock,
    post: postMock,
  },
}))

vi.mock('element-plus', async () => {
  const actual = await vi.importActual('element-plus')
  return {
    ...actual,
    ElMessage: {
      error: messageErrorMock,
      success: messageSuccessMock,
      warning: vi.fn(),
    },
  }
})

const buildListResponse = (items) => ({
  data: {
    data: items,
  },
})

const mountView = async () => {
  const wrapper = mount(CustomerPrePlanOrderView, {
    global: {
      plugins: [ElementPlus],
      stubs: {
        PrePlanOrderDetailContent: {
          template: '<div class="detail-stub">detail</div>',
        },
      },
    },
  })

  await flushPromises()

  return wrapper
}

describe('CustomerPrePlanOrderView', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    getMock.mockReset()
    postMock.mockReset()
    messageErrorMock.mockReset()
    messageSuccessMock.mockReset()

    getMock.mockResolvedValue({
      data: {
        cargo_categories: [],
      },
    })
  })

  afterEach(() => {
    vi.runOnlyPendingTimers()
    vi.useRealTimers()
  })

  it('加载通知后按未读优先和时间倒序展示', async () => {
    postMock
      .mockResolvedValueOnce(buildListResponse([]))
      .mockResolvedValueOnce(buildListResponse([
        {
          id: 1,
          title: '已读较新',
          read_at: '2026-03-30 09:30:00',
          created_at: '2026-03-30 09:30:00',
          meta: {},
        },
        {
          id: 2,
          title: '未读较早',
          read_at: null,
          created_at: '2026-03-30 08:30:00',
          meta: {},
        },
        {
          id: 3,
          title: '未读较新',
          read_at: null,
          created_at: '2026-03-30 10:30:00',
          meta: {},
        },
      ]))

    const wrapper = await mountView()

    expect(wrapper.vm.messages.map((item) => item.id)).toEqual([3, 2, 1])
  })

  it('从通知打开驳回订单详情时会继续加载版本对比', async () => {
    postMock
      .mockResolvedValueOnce(buildListResponse([]))
      .mockResolvedValueOnce(buildListResponse([
        {
          id: 10,
          title: '驳回通知',
          read_at: null,
          created_at: '2026-03-30 10:00:00',
          meta: {
            order_id: 201,
            order_no: 'PO-201',
            audit_status: 'rejected',
          },
        },
      ]))
      .mockResolvedValueOnce({
        data: {
          id: 201,
          audit_status: 'rejected',
          order_no: 'PO-201',
          meta: {},
        },
      })
      .mockResolvedValueOnce({
        data: {
          diffs: [{ field: 'dropoff_address', before: '旧门店', after: '新门店' }],
        },
      })

    const wrapper = await mountView()
    await wrapper.vm.openDetailById(201)
    await flushPromises()

    expect(postMock).toHaveBeenNthCalledWith(3, '/pre-plan-order/customer-detail', { id: 201 })
    expect(postMock).toHaveBeenNthCalledWith(4, '/pre-plan-order/revision-compare', { id: 201 })
    expect(wrapper.vm.detailDialogVisible).toBe(true)
    expect(wrapper.vm.detailCompareRows).toEqual([
      { field: 'dropoff_address', before: '旧门店', after: '新门店' },
    ])
  })

  it('标记通知已读后会刷新通知列表', async () => {
    postMock
      .mockResolvedValueOnce(buildListResponse([]))
      .mockResolvedValueOnce(buildListResponse([
        {
          id: 30,
          title: '未读审核通知',
          read_at: null,
          created_at: '2026-03-30 11:00:00',
          meta: {},
        },
      ]))
      .mockResolvedValueOnce({ data: { id: 30 } })
      .mockResolvedValueOnce(buildListResponse([
        {
          id: 30,
          title: '已读审核通知',
          read_at: '2026-03-30 11:05:00',
          created_at: '2026-03-30 11:00:00',
          meta: {},
        },
      ]))

    const wrapper = await mountView()
    await wrapper.vm.markMessageRead(wrapper.vm.messages[0])
    await flushPromises()

    expect(postMock).toHaveBeenNthCalledWith(3, '/message/read', { id: 30 })
    expect(postMock).toHaveBeenNthCalledWith(4, '/message/list', {
      unread_only: true,
    })
    expect(messageSuccessMock).toHaveBeenCalledWith('已标记为已读')
    expect(wrapper.vm.messages[0].read_at).toBe('2026-03-30 11:05:00')
  })

  it('创建表单填写完整后会防抖预览命中模板', async () => {
    postMock
      .mockResolvedValueOnce(buildListResponse([]))
      .mockResolvedValueOnce(buildListResponse([]))
      .mockResolvedValueOnce({
        data: {
          matched: true,
          template: {
            id: 88,
            name: '华东油品模板',
            pickup_site: { name: '上海库' },
            dropoff_site: { name: '苏州店' },
          },
        },
      })

    const wrapper = await mountView()
    wrapper.vm.openCreate()
    wrapper.vm.form.client_name = '华东客户'
    wrapper.vm.form.cargo_category_id = 9
    wrapper.vm.form.pickup_address = '上海装货地'
    wrapper.vm.form.dropoff_address = '苏州卸货地'

    await wrapper.vm.$nextTick()
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()

    expect(postMock).toHaveBeenNthCalledWith(3, '/freight-template/match-preview', {
      client_name: '华东客户',
      cargo_category_id: 9,
      pickup_address: '上海装货地',
      dropoff_address: '苏州卸货地',
    })
    expect(wrapper.vm.templatePreview).toEqual({
      id: 88,
      name: '华东油品模板',
      pickup_site: { name: '上海库' },
      dropoff_site: { name: '苏州店' },
    })
    expect(wrapper.text()).toContain('预计命中模板：华东油品模板（装货站点:上海库 / 卸货站点:苏州店）')
  })

  it('创建表单信息不完整时不会请求模板预览', async () => {
    postMock
      .mockResolvedValueOnce(buildListResponse([]))
      .mockResolvedValueOnce(buildListResponse([]))

    const wrapper = await mountView()
    wrapper.vm.openCreate()
    wrapper.vm.form.client_name = '信息不完整客户'
    wrapper.vm.form.cargo_category_id = null
    wrapper.vm.form.pickup_address = '上海装货地'
    wrapper.vm.form.dropoff_address = ''

    await wrapper.vm.$nextTick()
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()

    expect(postMock).toHaveBeenCalledTimes(2)
    expect(wrapper.vm.templatePreview).toBeNull()
  })
})
